import 'package:uuid/uuid.dart';

import 'clinical.dart';
import 'models.dart';

enum QueueState { waiting, withDoctor, seen, skipped }

QueueState queueStateFrom(String? s) => switch (s) {
      'with_doctor' => QueueState.withDoctor,
      'seen' => QueueState.seen,
      'skipped' => QueueState.skipped,
      _ => QueueState.waiting,
    };

String queueStateName(QueueState s) => switch (s) {
      QueueState.waiting => 'waiting',
      QueueState.withDoctor => 'with_doctor',
      QueueState.seen => 'seen',
      QueueState.skipped => 'skipped',
    };

/// Same record id for a visit on every device, so queue state is shared (last change wins).
String queueRecordId(int visitId) => const Uuid().v5(Namespace.url.value, 'opd_status:$visitId');

class QueueEntry {
  QueueEntry({required this.encounter, required this.patient, required this.state, required this.hasRx});
  final Encounter encounter;
  final Patient? patient;
  final QueueState state;
  final bool hasRx;

  int? get token => (encounter.j['token'] as num?)?.toInt();
}

/// Order and state of today's OPD queue. A visit with a prescription counts as seen unless marked otherwise.
List<QueueEntry> buildQueue({required List<Encounter> opd, required Map<int, Patient> patients, required Map<int, ClinicalRecord> statusByVisit, required bool Function(Encounter) hasRx}) {
  final list = [
    for (final e in opd)
      () {
        final rec = statusByVisit[e.refId];
        var state = queueStateFrom(rec?.str('state'));
        if (state == QueueState.waiting && hasRx(e)) state = QueueState.seen;
        return QueueEntry(encounter: e, patient: patients[e.patientId], state: state, hasRx: hasRx(e));
      }(),
  ];
  list.sort((a, b) {
    final ta = a.token, tb = b.token;
    if (ta != null && tb != null && ta != tb) return ta.compareTo(tb);
    if (ta != null && tb == null) return -1;
    if (ta == null && tb != null) return 1;
    return (a.encounter.date ?? DateTime(2999)).compareTo(b.encounter.date ?? DateTime(2999));
  });
  return list;
}

/// The record to save when changing a visit's queue state.
ClinicalRecord queueRecord(Encounter e, QueueState state, {ClinicalRecord? existing}) {
  final r = existing ??
      ClinicalRecord(uuid: queueRecordId(e.refId), patientId: e.patientId, type: RecordType.opdStatus, eventDate: e.date ?? DateTime.now());
  r.status = 'active';
  r.data
    ..['state'] = queueStateName(state)
    ..['visit_id'] = e.refId
    ..['token'] = e.j['token']
    ..['at'] = DateTime.now().toIso8601String();
  return r;
}
