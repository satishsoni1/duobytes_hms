import 'package:flutter/material.dart';

import 'core/auth/staff.dart';
import 'core/sync/sync_module.dart';
import 'features/doctor/data/doctor_sync.dart';
import 'features/account/account_screen.dart';
import 'features/doctor/ui/doctor_home.dart';
import 'features/doctor/ui/patients_screen.dart';
import 'features/staff/staff_screens.dart';

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
  // Every role shares the same offline store and sync; the server decides what each role pulls
  // (Mobilestaff_model) and checks web permissions on every upload.
  'reception': AppModule(
    id: 'reception',
    title: 'Reception',
    icon: Icons.how_to_reg_rounded,
    createSync: DoctorSyncModule.new,
    buildHome: () => StaffShell(tabs: [
      ('Queue', Icons.confirmation_number_outlined, const QueueBoardScreen(title: 'OPD queue')),
      ('Patients', Icons.people_outline_rounded, PatientsScreen(onOpenSyncDetails: () {})),
      ('Beds', Icons.bed_outlined, const BedBoardScreen()),
      ('Account', Icons.account_circle_outlined, const AccountScreen()),
    ]),
  ),
  'nursing': AppModule(
    id: 'nursing',
    title: 'Nursing',
    icon: Icons.vaccines_outlined,
    createSync: DoctorSyncModule.new,
    buildHome: () => StaffShell(tabs: [
      ('Ward', Icons.local_hotel_outlined, const WardScreen()),
      ('Triage', Icons.monitor_heart_outlined, const QueueBoardScreen(title: 'OPD triage', triage: true)),
      ('Beds', Icons.bed_outlined, const BedBoardScreen()),
      ('Account', Icons.account_circle_outlined, const AccountScreen()),
    ]),
  ),
  'lab': AppModule(
    id: 'lab',
    title: 'Laboratory',
    icon: Icons.biotech_outlined,
    createSync: DoctorSyncModule.new,
    buildHome: () => const StaffShell(tabs: [
      ('Worklist', Icons.science_outlined, LabWorklistScreen()),
      ('Account', Icons.account_circle_outlined, AccountScreen()),
    ]),
  ),
  'pharmacy': AppModule(
    id: 'pharmacy',
    title: 'Pharmacy',
    icon: Icons.medication_outlined,
    createSync: DoctorSyncModule.new,
    buildHome: () => const StaffShell(tabs: [
      ('Stock', Icons.medication_outlined, StockScreen()),
      ('Account', Icons.account_circle_outlined, AccountScreen()),
    ]),
  ),
};

List<AppModule> modulesFor(Staff staff) => [for (final id in staff.modules) ?appModules[id]];
