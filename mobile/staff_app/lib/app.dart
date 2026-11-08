import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/auth/auth_controller.dart';
import 'core/config.dart';
import 'core/providers.dart';
import 'core/sync/sync_triggers.dart';
import 'core/ui/theme.dart';
import 'core/ui/widgets.dart';
import 'features/auth/login_screen.dart';
import 'features/patient/ui/patient_home.dart';
import 'features/patient/ui/patient_providers.dart';
import 'modules.dart';

class StaffApp extends ConsumerWidget {
  const StaffApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    final seed = switch (auth) {
      SignedIn() => parseHexColor(auth.session.hospital.primaryColor),
      PatientSignedIn() => parseHexColor(auth.session.hospital.primaryColor),
      _ => null,
    };
    return MaterialApp(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      // Always the light healthcare theme, also when the phone is in dark mode.
      theme: buildTheme(seed: seed),
      themeMode: ThemeMode.light,
      home: switch (auth) {
        AuthLoading() => const _Splash(),
        SignedOut() => const LoginScreen(),
        SignedIn() => SyncTriggers(onSync: (ref) => ref.read(syncEngineProvider.notifier).sync(), child: const _ModuleHome()),
        PatientSignedIn() => SyncTriggers(onSync: (ref) => ref.read(patientSyncProvider.notifier).refresh(), child: const PatientHome()),
      },
    );
  }
}

class _Splash extends StatelessWidget {
  const _Splash();
  @override
  Widget build(BuildContext context) => const Scaffold(body: Center(child: CircularProgressIndicator()));
}

/// Phase 1 has one module per role, so its home is shown directly. With several modules this
/// becomes a switcher.
class _ModuleHome extends ConsumerWidget {
  const _ModuleHome();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final modules = modulesFor(ref.watch(staffProvider));
    if (modules.isEmpty) {
      return Scaffold(
        body: EmptyState(
          icon: Icons.lock_outline,
          title: 'Nothing here for your role yet',
          message: 'This version of the app supports doctors. Other roles are coming in a later update.',
          action: OutlinedButton(onPressed: () => ref.read(authControllerProvider.notifier).signOut(), child: const Text('Sign out')),
        ),
      );
    }
    return modules.first.buildHome();
  }
}
