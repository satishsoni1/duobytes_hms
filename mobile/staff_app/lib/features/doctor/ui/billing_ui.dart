import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../core/providers.dart';
import '../../../core/ui/theme.dart';
import '../../../core/ui/widgets.dart';
import '../data/billing.dart';
import '../data/models.dart';
import 'doctor_providers.dart';

final billingRepoProvider = Provider((ref) => BillingRepository(ref.watch(databaseProvider)));

final patientBillProvider = FutureProvider.autoDispose.family<PatientBill, int>((ref, patientId) {
  ref.watch(dataRevisionProvider);
  return ref.watch(billingRepoProvider).bill(patientId);
});

final chargeOptionsProvider = FutureProvider.autoDispose<List<ChargeOption>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(billingRepoProvider).chargeOptions();
});

final _money = NumberFormat.currency(locale: 'en_IN', symbol: '₹', decimalDigits: 2);
String money(double v) => _money.format(v);

/// Patient screen tab: services charged, payments, balance, and "Add service".
class BillingTab extends ConsumerWidget {
  const BillingTab({super.key, required this.d});
  final PatientDetail d;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final bill = ref.watch(patientBillProvider(d.patient.id)).valueOrNull;
    if (bill == null) return const SkeletonList(count: 3);
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final enc = d.openEncounters.firstOrNull ?? d.encounters.firstOrNull;

    return ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 110), children: [
      FadeSlideIn(
        child: Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(children: [
              Expanded(child: _Amount(label: 'Charges', value: bill.totalCharges)),
              Expanded(child: _Amount(label: 'Paid', value: bill.totalPaid, color: cs.tertiary)),
              Expanded(child: _Amount(label: 'Balance', value: bill.balance, color: bill.balance > 0.009 ? cs.error : cs.tertiary, bold: true)),
            ]),
          ),
        ),
      ),
      const SizedBox(height: 10),
      if (enc != null && enc.isOpen)
        FilledButton.tonalIcon(
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
          onPressed: () => showAddServiceSheet(context, ref, enc),
          icon: const Icon(Icons.add_card_rounded),
          label: Text(enc.isIpd ? 'Add service to this admission' : 'Add service to this visit'),
        )
      else
        Text('Services can be added while a visit or admission is open.', style: t.textTheme.bodySmall?.copyWith(color: cs.onSurfaceVariant)),

      if (bill.pending.isNotEmpty) ...[
        const SectionHeader('Waiting to upload', padding: EdgeInsets.fromLTRB(4, 20, 0, 8)),
        Card(
          child: Column(children: [
            for (final p in bill.pending)
              ListTile(
                leading: IconBadge(p.error == null ? Icons.cloud_upload_rounded : Icons.error_outline_rounded, color: p.error == null ? cs.secondary : cs.error),
                title: Text('${p.name}${p.qty > 1 ? ' × ${p.qty}' : ''}'),
                subtitle: Text(p.error ?? 'Will be added to the bill when online'),
                trailing: p.error == null
                    ? Text(money(p.total), style: t.textTheme.titleSmall)
                    : IconButton(
                        tooltip: 'Remove',
                        icon: const Icon(Icons.close_rounded),
                        onPressed: () async {
                          await ref.read(billingRepoProvider).dismissPending(d.patient.id, p.opId);
                          afterLocalWrite(ref);
                        },
                      ),
              ),
          ]),
        ),
      ],

      SectionHeader('Services charged', count: bill.charges.length, padding: const EdgeInsets.fromLTRB(4, 20, 0, 8)),
      if (bill.charges.isEmpty)
        Text('No charges on record.', style: t.textTheme.bodySmall)
      else
        Card(
          child: Column(children: [
            for (final c in bill.charges)
              ListTile(
                dense: true,
                title: Text('${c.name}${c.qty > 1 ? ' × ${c.qty}' : ''}'),
                subtitle: Text([fmtDate(c.date), c.category, if (c.taxPercent > 0) 'tax ${c.taxPercent.toStringAsFixed(0)}%', c.note].whereType<String>().where((s) => s.isNotEmpty).join(' · ')),
                trailing: Text(money(c.amount), style: t.textTheme.titleSmall),
              ),
          ]),
        ),

      if (bill.payments.isNotEmpty) ...[
        SectionHeader('Payments', count: bill.payments.length, padding: const EdgeInsets.fromLTRB(4, 20, 0, 8)),
        Card(
          child: Column(children: [
            for (final p in bill.payments)
              ListTile(
                dense: true,
                leading: Icon(p.isRefund ? Icons.undo_rounded : Icons.payments_rounded, color: p.isRefund ? cs.error : cs.tertiary),
                title: Text(p.isRefund ? 'Refund' : 'Payment${p.mode != null ? ' · ${p.mode}' : ''}'),
                subtitle: Text(fmtDateTime(p.date)),
                trailing: Text('${p.isRefund ? '-' : ''}${money(p.amount)}', style: t.textTheme.titleSmall),
              ),
          ]),
        ),
      ],
      Padding(
        padding: const EdgeInsets.only(top: 12),
        child: Text('Payments are collected at the billing counter. The bill updates when the phone syncs.', textAlign: TextAlign.center, style: t.textTheme.labelSmall?.copyWith(color: cs.onSurfaceVariant)),
      ),
    ]);
  }
}

class _Amount extends StatelessWidget {
  const _Amount({required this.label, required this.value, this.color, this.bold = false});
  final String label;
  final double value;
  final Color? color;
  final bool bold;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    return Column(children: [
      Text(label, style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onSurfaceVariant)),
      const SizedBox(height: 4),
      FittedBox(child: Text(money(value), style: (bold ? t.textTheme.titleLarge : t.textTheme.titleMedium)?.copyWith(color: color))),
    ]);
  }
}

/// Pick a service from the hospital charge list and add it to the visit.
Future<void> showAddServiceSheet(BuildContext context, WidgetRef ref, Encounter encounter) async {
  final added = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (_) => Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SizedBox(height: MediaQuery.sizeOf(context).height * 0.85, child: _AddService(encounter: encounter)),
    ),
  );
  if (added == true && context.mounted) showSnack(context, 'Service added. It will reach the bill when online.', icon: Icons.add_card_rounded);
}

class _AddService extends ConsumerStatefulWidget {
  const _AddService({required this.encounter});
  final Encounter encounter;

  @override
  ConsumerState<_AddService> createState() => _AddServiceState();
}

class _AddServiceState extends ConsumerState<_AddService> {
  String _q = '';
  ChargeOption? _pick;
  int _qty = 1;
  double _discount = 0;
  final _note = TextEditingController();

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final cs = t.colorScheme;
    final all = ref.watch(chargeOptionsProvider).valueOrNull ?? const <ChargeOption>[];
    final terms = _q.toLowerCase().split(RegExp(r'\s+')).where((x) => x.isNotEmpty).toList();
    final list = all.where((c) {
      final hay = '${c.name} ${c.category ?? ''} ${c.type ?? ''}'.toLowerCase();
      return terms.every(hay.contains);
    }).take(80).toList();

    if (_pick != null) {
      final c = _pick!;
      final price = c.price(_qty, _discount);
      return ListView(padding: const EdgeInsets.fromLTRB(20, 0, 20, 20), children: [
        Row(children: [
          IconButton(onPressed: () => setState(() => _pick = null), icon: const Icon(Icons.arrow_back_rounded)),
          Expanded(child: Text(c.name, style: t.textTheme.titleLarge)),
        ]),
        Text([c.type, c.category, '${money(c.standardCharge)}${c.unit != null ? ' per ${c.unit}' : ''}'].whereType<String>().join(' · '), style: t.textTheme.bodySmall),
        const SizedBox(height: 18),
        Row(children: [
          const Text('Quantity'),
          const Spacer(),
          IconButton.filledTonal(onPressed: _qty > 1 ? () => setState(() => _qty--) : null, icon: const Icon(Icons.remove_rounded)),
          SizedBox(width: 44, child: Text('$_qty', textAlign: TextAlign.center, style: t.textTheme.titleLarge)),
          IconButton.filledTonal(onPressed: _qty < 100 ? () => setState(() => _qty++) : null, icon: const Icon(Icons.add_rounded)),
        ]),
        const SizedBox(height: 10),
        Text('Discount: ${_discount.round()}%', style: t.textTheme.labelMedium),
        Slider(value: _discount, min: 0, max: 100, divisions: 20, label: '${_discount.round()}%', onChanged: (v) => setState(() => _discount = v)),
        TextField(controller: _note, decoration: const InputDecoration(labelText: 'Note (optional)')),
        const SizedBox(height: 16),
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(color: cs.primaryContainer, borderRadius: BorderRadius.circular(Radii.md)),
          child: Column(children: [
            _line(context, 'Charge', money(price.apply)),
            if (c.taxPercent > 0) _line(context, 'Tax ${c.taxPercent.toStringAsFixed(c.taxPercent % 1 == 0 ? 0 : 1)}%', money(price.tax)),
            const Divider(),
            _line(context, 'Total', money(price.total), bold: true),
          ]),
        ),
        const SizedBox(height: 16),
        FilledButton.icon(
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
          onPressed: () async {
            await ref.read(billingRepoProvider).addService(encounter: widget.encounter, charge: c, qty: _qty, discount: _discount, note: _note.text.trim());
            HapticFeedback.mediumImpact();
            afterLocalWrite(ref);
            if (context.mounted) Navigator.pop(context, true);
          },
          icon: const Icon(Icons.check_rounded),
          label: Text('Add ${money(price.total)}'),
        ),
      ]);
    }

    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
        child: TextField(
          autofocus: true,
          onChanged: (v) => setState(() => _q = v.trim()),
          decoration: const InputDecoration(hintText: 'Search service, e.g. dressing, injection, ECG', prefixIcon: Icon(Icons.search_rounded)),
        ),
      ),
      Expanded(
        child: all.isEmpty
            ? const EmptyState(icon: Icons.cloud_download_rounded, title: 'Service list not downloaded yet', message: 'Connect to the internet once so the hospital charge list is saved on this phone.')
            : list.isEmpty
                ? const EmptyState(icon: Icons.search_off_rounded, title: 'No matching service')
                : ListView.builder(
                    itemCount: list.length,
                    itemBuilder: (_, i) {
                      final c = list[i];
                      return ListTile(
                        title: Text(c.name),
                        subtitle: Text([c.type, c.category].whereType<String>().where((s) => s.isNotEmpty).join(' · ')),
                        trailing: Text(money(c.standardCharge), style: t.textTheme.titleSmall),
                        onTap: () => setState(() => _pick = c),
                      );
                    },
                  ),
      ),
    ]);
  }

  Widget _line(BuildContext context, String l, String v, {bool bold = false}) {
    final style = bold ? Theme.of(context).textTheme.titleMedium : Theme.of(context).textTheme.bodyMedium;
    return Padding(padding: const EdgeInsets.symmetric(vertical: 2), child: Row(children: [Text(l, style: style), const Spacer(), Text(v, style: style)]));
  }
}
