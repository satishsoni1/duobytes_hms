import 'package:flutter/material.dart';

import 'core/auth/staff.dart';
import 'core/sync/sync_module.dart';
import 'features/doctor/data/doctor_sync.dart';
import 'features/doctor/ui/doctor_home.dart';

/// A role-specific section of the app. The server decides which ones a user gets
/// (staff.modules, from Mobileapi_model::$role_modules).
///
/// To add nursing later: build features/nursing/, register it here as 'nursing', and map the
/// Nurse role to it on the server. Sign-in, offline storage and sync are shared.
class AppModule {
  const AppModule({required this.id, required this.title, required this.icon, required this.createSync, required this.buildHome});

  final String id;
  final String title;
  final IconData icon;
  final SyncModule Function() createSync;
  final Widget Function() buildHome;
}

final Map<String, AppModule> appModules = {
  'doctor': AppModule(
    id: 'doctor',
    title: 'Doctor',
    icon: Icons.medical_services_outlined,
    createSync: DoctorSyncModule.new,
    buildHome: () => const DoctorHome(),
  ),
};

List<AppModule> modulesFor(Staff staff) => [for (final id in staff.modules) ?appModules[id]];
