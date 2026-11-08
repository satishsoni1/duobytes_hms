import 'package:sqflite_common/sqlite_api.dart';

import '../api/api_client.dart';
import '../db/app_database.dart';

/// One queued change waiting to be pushed.
class OutboxOp {
  OutboxOp(this.row);
  final Map<String, Object?> row;

  int get seq => row['seq'] as int;
  String get opId => row['op_id'] as String;
  String get type => row['type'] as String;
  String? get refLocalId => row['ref_local_id'] as String?;
  String? get payload => row['payload'] as String?;
  int? get sentRev => row['sent_rev'] as int?;
  int get attempts => row['attempts'] as int;
}

/// What a feature module (doctor today; nursing etc. later) contributes to sync.
abstract class SyncModule {
  /// Reference data (medicine lists etc.). Called before [pull].
  Future<void> pullMasters(ApiClient api, AppDatabase adb);

  /// Download changes since the last pull and apply them locally.
  Future<void> pull(ApiClient api, AppDatabase adb);

  bool handles(String opType);

  /// Payload to send for [op], built from current local data. Return null to drop the op
  /// (e.g. the record was deleted locally). May record bookkeeping such as the revision sent.
  Future<Map<String, dynamic>?> buildPayload(Transaction tx, OutboxOp op);

  /// Apply a final server answer (status ok / rejected / conflict). The engine deletes the op afterwards.
  Future<void> applyResult(Transaction tx, OutboxOp op, Map<String, dynamic> result);
}
