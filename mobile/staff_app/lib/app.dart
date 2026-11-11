import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/auth/auth_controller.dart';
import 'core/config.dart';
import 'core/providers.dart';
import 'core/lan/lan_service.dart';
import 'core/security/app_lock.dart';
import 'core/security/lock_screen.dart';
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
    // The app lock belongs to the signed-in user: forget it on sign-out.
    ref.listen(authControllerProvider, (prev, next) {
      if (prev is SignedInBase && next is SignedOut) ref.read(appLockProvider.notifier).clear();
    });
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
        SignedIn() => AppLockGate(child: SyncTriggers(onSync: (ref) => ref.read(syncEngineProvider.notifier).sync(), child: const _ModuleHome())),
        PatientSignedIn() => AppLockGate(child: SyncTriggers(onSync: (ref) => ref.read(patientSyncProvider.notifier).refresh(), child: const PatientHome())),
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
    ref.watch(lanProvider.select((s) => s.mode)); // restores hotspot sharing set up earlier
    if (modules.isEmpty) {
      return Scaffold(
        body: EmptyState(
          icon: Icons.lock_outline,
          title: 'Nothing here for your role yet',
          message: 'The app supports doctors, reception, nurses, lab and pharmacy. Ask the hospital admin to give your account one of these roles.',
          action: OutlinedButton(onPressed: () => ref.read(authControllerProvider.notifier).signOut(), child: const Text('Sign out')),
        ),
      );
    }
    return modules.first.buildHome();
  }
}
