import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config.dart';
import '../providers.dart';

/// Starts a sync when the app opens, comes back to the foreground, regains network, and every few
/// minutes while it is open. Changes saved on the device also schedule a push (see SyncEngine.scheduleSoon).
class SyncTriggers extends ConsumerStatefulWidget {
  const SyncTriggers({super.key, required this.child, required this.onSync});
  final Widget child;

  /// Runs one sync (staff: push/pull engine; patient: refresh own records).
  final void Function(WidgetRef ref) onSync;

  @override
  ConsumerState<SyncTriggers> createState() => _SyncTriggersState();
}

class _SyncTriggersState extends ConsumerState<SyncTriggers> with WidgetsBindingObserver {
  StreamSubscription<List<ConnectivityResult>>? _net;
  Timer? _timer;
  bool _wasOffline = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _sync();
      ref.read(authControllerProvider.notifier).refreshProfile();
    });
    _net = Connectivity().onConnectivityChanged.listen((r) {
      final offline = r.every((c) => c == ConnectivityResult.none);
      if (_wasOffline && !offline) _sync();
      _wasOffline = offline;
    });
    _timer = Timer.periodic(AppConfig.periodicSync, (_) => _sync());
  }

  void _sync() {
    if (!mounted || ref.read(tokenExpiredProvider)) return;
    widget.onSync(ref);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _sync();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _net?.cancel();
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
