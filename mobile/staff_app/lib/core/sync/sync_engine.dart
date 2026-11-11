import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../db/app_database.dart';
import 'sync_module.dart';

enum SyncPhase { idle, syncing, offline, needsSignIn, error }

class SyncStatus {
  const SyncStatus({
    this.phase = SyncPhase.idle,
    this.lastSyncAt,
    this.pending = 0,
    this.conflicts = 0,
    this.rejected = 0,
    this.message,
    this.hasSyncedOnce = false,
  });

  final SyncPhase phase;
  final DateTime? lastSyncAt;
  final int pending;
  final int conflicts;
  final int rejected;
  final String? message;
  final bool hasSyncedOnce;

  bool get needsAttention => conflicts > 0 || rejected > 0;

  SyncStatus copyWith({SyncPhase? phase, DateTime? lastSyncAt, int? pending, int? conflicts, int? rejected, String? message, bool clearMessage = false, bool? hasSyncedOnce}) =>
      SyncStatus(
        phase: phase ?? this.phase,
        lastSyncAt: lastSyncAt ?? this.lastSyncAt,
        pending: pending ?? this.pending,
        conflicts: conflicts ?? this.conflicts,
        rejected: rejected ?? this.rejected,
        message: clearMessage ? null : (message ?? this.message),
        hasSyncedOnce: hasSyncedOnce ?? this.hasSyncedOnce,
      );
}

/// Push-then-pull sync. Local changes are never lost: they stay in the outbox until the server gives a
/// final answer, and a failed request just leaves them there for the next attempt.
class SyncEngine extends StateNotifier<SyncStatus> {
  SyncEngine({
    required this.api,
    required this.adb,
    required this.modules,
    required this.onDataChanged,
    required this.onUnauthorized,
  }) : super(const SyncStatus()) {
    _loadInitial();
  }

  final ApiClient api;
  final AppDatabase adb;
  final List<SyncModule> modules;
  final void Function() onDataChanged;
  final void Function() onUnauthorized;

  static const _batchSize = 25;

  Future<void>? _running;
  bool _again = false;
  Timer? _debounce;
  bool _disposed = false;

  Future<void> _loadInitial() async {
    final last = await adb.getMeta('last_sync_at');
    if (_disposed) return;
    state = state.copyWith(lastSyncAt: last == null ? null : DateTime.tryParse(last), hasSyncedOnce: last != null);
    await refreshCounts();
  }

  /// Sync shortly; repeated calls within the window collapse into one run.
  void scheduleSoon([Duration delay = const Duration(seconds: 2)]) {
    _debounce?.cancel();
    _debounce = Timer(delay, () => sync());
  }

  /// Run a sync now. If one is already running, another run follows it (so nothing saved meanwhile waits).
  Future<void> sync() {
    if (_running != null) {
      _again = true;
      return _running!;
    }
    final f = _run();
    _running = f;
    return f;
  }

  Future<void> _run() async {
    try {
      do {
        _again = false;
        await _once();
      } while (_again && !_disposed);
    } finally {
      _running = null;
    }
  }

  Future<void> _once() async {
    if (_disposed) return;
    state = state.copyWith(phase: SyncPhase.syncing, clearMessage: true);
    try {
      await push();
      for (final m in modules) {
        await m.pullMasters(api, adb);
      }
      for (final m in modules) {
        await m.pull(api, adb);
      }
      final now = DateTime.now();
      await adb.setMeta('last_sync_at', now.toIso8601String());
      if (_disposed) return;
      state = state.copyWith(phase: SyncPhase.idle, lastSyncAt: now, hasSyncedOnce: true);
    } on ApiException catch (e) {
      if (_disposed) return;
      if (e.kind == ApiErrorKind.unauthorized) {
        state = state.copyWith(phase: SyncPhase.needsSignIn, message: e.message);
        onUnauthorized();
      } else {
        state = state.copyWith(phase: e.isOffline ? SyncPhase.offline : SyncPhase.error, message: e.message);
      }
    } catch (e) {
      if (_disposed) return;
      state = state.copyWith(phase: SyncPhase.error, message: 'Sync failed: $e');
    } finally {
      await refreshCounts();
      onDataChanged();
    }
  }

  /// Send queued changes in order, in batches. Transient failures stay queued for the next run.
  Future<void> push() async {
    final failedThisRun = <String>{};
    while (true) {
      final rows = await adb.db.query('outbox', orderBy: 'seq', limit: _batchSize + failedThisRun.length);
      final ops = rows.map(OutboxOp.new).where((o) => !failedThisRun.contains(o.opId)).take(_batchSize).toList();
      if (ops.isEmpty) return;

      final payloads = <Map<String, dynamic>>[];
      final sent = <OutboxOp>[];
      await adb.db.transaction((tx) async {
        for (final op in ops) {
          final module = _moduleFor(op.type);
          if (module != null && !await module.isReady(tx, op)) {
            failedThisRun.add(op.opId); // waits for an earlier op (e.g. patient registration); not an attempt
            continue;
          }
          final payload = module == null ? null : await module.buildPayload(tx, op);
          if (payload == null) {
            await tx.delete('outbox', where: 'seq = ?', whereArgs: [op.seq]);
            continue;
          }
          payloads.add({'op_id': op.opId, 'type': op.type, 'payload': payload});
          sent.add(op);
          if (module!.isBarrier(op.type)) break;
        }
      });
      if (sent.isEmpty) continue;

      final res = await api.post('sync/push', {'ops': payloads});
      final results = {
        for (final r in (res['results'] as List? ?? const [])) '${(r as Map)['op_id']}': Map<String, dynamic>.from(r),
      };

      // Re-read ops: buildPayload may have updated their bookkeeping (e.g. sent_rev).
      final fresh = {
        for (final r in await adb.db.query('outbox', where: 'op_id IN (${List.filled(sent.length, '?').join(',')})', whereArgs: sent.map((o) => o.opId).toList()))
          r['op_id'] as String: OutboxOp(r),
      };

      await adb.db.transaction((tx) async {
        for (final op in sent) {
          final current = fresh[op.opId] ?? op;
          final r = results[op.opId];
          final status = r?['status'];
          if (status == 'ok' || status == 'rejected' || status == 'conflict') {
            await _moduleFor(op.type)!.applyResult(tx, current, r!);
            await tx.delete('outbox', where: 'op_id = ?', whereArgs: [op.opId]);
          } else {
            failedThisRun.add(op.opId);
            await tx.update('outbox', {'attempts': current.attempts + 1, 'last_error': r?['message'] ?? 'No answer from server'},
                where: 'op_id = ?', whereArgs: [op.opId]);
          }
        }
      });
      onDataChanged();
    }
  }

  SyncModule? _moduleFor(String type) {
    for (final m in modules) {
      if (m.handles(type)) return m;
    }
    return null;
  }

  Future<void> refreshCounts() async {
    final db = adb.db;
    final pending = (await db.rawQuery('SELECT COUNT(*) n FROM outbox')).first['n'] as int;
    final c = (await db.rawQuery("SELECT COUNT(*) n FROM prescriptions WHERE status = 'conflict'")).first['n'] as int;
    final rj = (await db.rawQuery("SELECT COUNT(*) n FROM prescriptions WHERE status = 'rejected'")).first['n'] as int;
    if (_disposed) return;
    state = state.copyWith(pending: pending, conflicts: c, rejected: rj);
  }

  @override
  void dispose() {
    _disposed = true;
    _debounce?.cancel();
    super.dispose();
  }
}
