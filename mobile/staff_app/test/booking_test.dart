import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/intl.dart';
import 'package:staff_app/core/auth/auth_controller.dart';
import 'package:staff_app/core/auth/patient_account.dart';
import 'package:staff_app/core/auth/session_store.dart';
import 'package:staff_app/core/db/app_database.dart';
import 'package:staff_app/core/providers.dart';
import 'package:staff_app/core/ui/theme.dart';
import 'package:staff_app/features/patient/ui/booking.dart';

import 'support/fake_server.dart';

class _FakeAuth extends AuthController {
  _FakeAuth(AuthState s) : super(SessionStore()) {
    state = s;
  }
}

void main() {
  late AppDatabase adb;
  late FakeServer server;
  final tomorrow = DateTime.now().add(const Duration(days: 1));
  final workDay = DateFormat('EEEE').format(tomorrow);
  var takenOnBook = false;

  Widget app(Widget home) {
    final session = PatientSession.fromJson({
      'patient': {'user_id': 31, 'patient_id': 1, 'username': 'pat1', 'name': 'Asha Patil'},
      'modules': {'my_appointments': true},
      'hospital': {'hospital_name': 'Skylark Hospital'},
    });
    return ProviderScope(
      overrides: [authControllerProvider.overrideWith((ref) => _FakeAuth(PatientSignedIn(session: session, serverUrl: 'x', api: server.client(), db: adb)))],
      child: MaterialApp(theme: buildTheme(), home: home),
    );
  }

  // Network/DB work completes outside the fake clock: pump until no spinner is left (bounded).
  Future<void> settle(WidgetTester t) async {
    for (var i = 0; i < 8 || (i < 80 && find.byType(CircularProgressIndicator).evaluate().isNotEmpty); i++) {
      await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 40)));
      await t.pump(const Duration(milliseconds: 50));
    }
    await t.pumpAndSettle();
  }

  setUp(() {
    takenOnBook = false;
    server = FakeServer();
    server.routes['patient/doctors'] = (_, _) async => (200, {
          'doctors': [
            {'id': 7, 'name': 'Dr. Anil Mehta', 'specialties': ['Paediatrics'], 'fee': 400, 'consult_minutes': 15, 'days': [workDay]},
            {'id': 8, 'name': 'Dr. Neha Joshi', 'specialties': ['Gynaecology'], 'fee': 500, 'consult_minutes': 10, 'days': ['Sunday']},
          ],
        });
    server.routes['patient/slots'] = (_, o) async => (200, {
          'date': o.queryParameters['date'],
          'shifts': [
            {
              'shift_id': 33, 'global_shift_id': 1, 'name': 'Morning', 'start': '10:00', 'end': '11:00',
              'slots': [
                {'time': '10:00', 'available': false},
                {'time': '10:15', 'available': true},
                {'time': '10:30', 'available': true},
                {'time': '10:45', 'available': true},
              ],
            },
          ],
        });
    server.routes['patient/book'] = (_, _) async => takenOnBook
        ? (409, {'error': {'code': 'slot_unavailable', 'message': 'Someone just booked that slot. Please pick another.'}})
        : (200, {'ok': true, 'appointment_id': 55, 'status': 'pending'});
    server.routes['patient/data'] = (_, _) async => (304, null);
    server.routes['patient/queue'] = (_, _) async => (200, {
          'queue': [
            {'appointment_id': 55, 'doctor_name': 'Dr. Anil Mehta', 'time': '${today()} 10:30:00', 'status': 'approved', 'token': 6, 'checked_in': true, 'seen': false, 'ahead': 3, 'now_serving': {'token': 3}, 'wait_minutes': 45},
          ],
        });
  });

  testWidgets('book: filter doctors, only working days, booked slots disabled, exact payload', (t) async {
    await t.runAsync(() async => adb = await openTestDb());
    t.view.physicalSize = const Size(1080, 2340);
    t.view.devicePixelRatio = 3;
    addTearDown(t.view.reset);
    await t.pumpWidget(app(const BookAppointmentScreen()));
    await settle(t);

    expect(find.text('Dr. Anil Mehta'), findsOneWidget);
    expect(find.text('Dr. Neha Joshi'), findsOneWidget);
    await t.ensureVisible(find.widgetWithText(FilterChip, 'Paediatrics'));
    await t.pumpAndSettle();
    await t.tap(find.widgetWithText(FilterChip, 'Paediatrics'));
    await t.pumpAndSettle();
    expect(find.text('Dr. Neha Joshi'), findsNothing);

    await t.tap(find.text('Dr. Anil Mehta'));
    await settle(t);
    expect(find.textContaining('Morning'), findsOneWidget);
    final booked = t.widget<ChoiceChip>(find.widgetWithText(ChoiceChip, '10:00 AM'));
    expect(booked.onSelected, isNull, reason: 'already booked');

    await t.tap(find.widgetWithText(ChoiceChip, '10:30 AM'));
    await t.pumpAndSettle();
    await t.enterText(find.byType(TextField), 'Fever since 2 days');
    await t.tap(find.textContaining('Book ${DateFormat('d MMM').format(tomorrow)}, 10:30 AM'));
    await settle(t);

    final body = server.bodiesFor('patient/book').single!;
    expect(body, {'doctor_id': 7, 'shift_id': 33, 'date': DateFormat('yyyy-MM-dd').format(tomorrow), 'time': '10:30', 'message': 'Fever since 2 days'});
    expect(find.text('Appointment requested'), findsOneWidget);
    expect(find.textContaining('paid at the hospital'), findsOneWidget);
    expect(t.takeException(), isNull);
  });

  testWidgets('a slot taken by someone else shows the reason and reloads availability', (t) async {
    await t.runAsync(() async => adb = await openTestDb());
    t.view.physicalSize = const Size(1080, 2340);
    t.view.devicePixelRatio = 3;
    addTearDown(t.view.reset);
    takenOnBook = true;
    await t.pumpWidget(app(const BookAppointmentScreen()));
    await settle(t);
    await t.tap(find.text('Dr. Anil Mehta'));
    await settle(t);
    await t.tap(find.widgetWithText(ChoiceChip, '10:15 AM'));
    await t.pumpAndSettle();
    await t.tap(find.textContaining('10:15 AM').last);
    await settle(t);
    expect(find.textContaining('Someone just booked that slot'), findsOneWidget);
    expect(server.bodiesFor('patient/slots').length, 2, reason: 'availability reloaded');
  });

  testWidgets('live queue card shows token, people ahead, now serving and wait', (t) async {
    await t.runAsync(() async => adb = await openTestDb());
    await t.pumpWidget(app(const Scaffold(body: SingleChildScrollView(child: LiveQueueCard()))));
    // The "doctor is seeing patients" dot pulses continuously, so pump frames instead of settling.
    for (var i = 0; i < 8; i++) {
      await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 40)));
      await t.pump(const Duration(milliseconds: 100));
    }
    await t.pump(const Duration(seconds: 1));
    expect(find.textContaining('Your queue today'), findsOneWidget);
    expect(find.text('6'), findsOneWidget);
    expect(find.text('3'), findsOneWidget);
    expect(find.text('45'), findsOneWidget);
    expect(find.textContaining('Now seeing token 3'), findsOneWidget);
    await t.pumpWidget(const SizedBox()); // stop the refresh timer
  });
}
