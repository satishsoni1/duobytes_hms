import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/providers.dart';
import '../../../core/ui/motion.dart';
import '../../account/account_screen.dart';
import 'patients_screen.dart';
import 'reports_screen.dart';
import 'today_screen.dart';

class DoctorHome extends ConsumerStatefulWidget {
  const DoctorHome({super.key});

  @override
  ConsumerState<DoctorHome> createState() => _DoctorHomeState();
}

class _DoctorHomeState extends ConsumerState<DoctorHome> {
  int _tab = 0;

  void _openAccount() => setState(() => _tab = 3);

  @override
  Widget build(BuildContext context) {
    final sync = ref.watch(syncEngineProvider);
    final attention = sync.needsAttention || ref.watch(tokenExpiredProvider);
    final newReports = ref.watch(newReportsCountProvider);
    return Scaffold(
      body: FadeIndexedStack(index: _tab, children: [
        TodayScreen(onOpenSyncDetails: _openAccount),
        PatientsScreen(onOpenSyncDetails: _openAccount),
        ReportsScreen(onOpenSyncDetails: _openAccount),
        const AccountScreen(),
      ]),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) {
          if (i != _tab) HapticFeedback.selectionClick();
          setState(() => _tab = i);
        },
        destinations: [
          const NavigationDestination(icon: Icon(Icons.today_outlined), selectedIcon: Icon(Icons.today_rounded), label: 'Today'),
          const NavigationDestination(icon: Icon(Icons.people_outline_rounded), selectedIcon: Icon(Icons.people_rounded), label: 'Patients'),
          NavigationDestination(
            icon: Badge(isLabelVisible: newReports > 0, label: Text('$newReports'), child: const Icon(Icons.biotech_outlined)),
            selectedIcon: const Icon(Icons.biotech_rounded),
            label: 'Reports',
          ),
          NavigationDestination(
            icon: Badge(isLabelVisible: attention || sync.pending > 0, label: attention ? const Text('!') : Text('${sync.pending}'), child: const Icon(Icons.account_circle_outlined)),
            selectedIcon: const Icon(Icons.account_circle_rounded),
            label: 'Account',
          ),
        ],
      ),
    );
  }
}
