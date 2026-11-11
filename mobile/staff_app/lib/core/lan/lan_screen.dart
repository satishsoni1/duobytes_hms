import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../ui/widgets.dart';
import 'lan_service.dart';

/// Account → Clinic devices: share the queue and new registrations between devices on one hotspot.
class LanScreen extends ConsumerStatefulWidget {
  const LanScreen({super.key});
  @override
  ConsumerState<LanScreen> createState() => _LanScreenState();
}

class _LanScreenState extends ConsumerState<LanScreen> {
  final _host = TextEditingController();
  final _code = TextEditingController();
  bool _searching = false;

  @override
  void dispose() {
    _host.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _discover() async {
    setState(() => _searching = true);
    await ref.read(lanProvider.notifier).discover();
    if (mounted) setState(() => _searching = false);
  }

  @override
  Widget build(BuildContext context) {
    final st = ref.watch(lanProvider);
    final lan = ref.read(lanProvider.notifier);
    final t = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Clinic devices')),
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 24), children: [
        Text(
          'Devices on the same phone hotspot or Wi-Fi share new patients, today\'s queue and triage vitals with each other, with no internet. '
          'Each device still uploads its own work to the cloud when it gets internet.',
          style: t.textTheme.bodyMedium,
        ),
        const SizedBox(height: 16),
        if (st.error != null)
          Card(color: t.colorScheme.errorContainer, child: ListTile(leading: const Icon(Icons.error_outline_rounded), title: Text(st.error!))),
        switch (st.mode) {
          LanMode.off => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Card(
                child: ListTile(
                  leading: const IconBadge(Icons.hub_rounded),
                  title: const Text('Make this device the hub'),
                  subtitle: const Text('Usually the reception tablet. Others connect to it with a code.'),
                  onTap: () => lan.startHub(),
                ),
              ),
              const SectionHeader('Connect to the hub', padding: EdgeInsets.fromLTRB(4, 16, 0, 8)),
              OutlinedButton.icon(
                onPressed: _searching ? null : _discover,
                icon: _searching ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.wifi_find_rounded),
                label: Text(_searching ? 'Looking for hubs…' : 'Find hubs nearby'),
              ),
              for (final h in st.discovered)
                ListTile(leading: const Icon(Icons.tablet_android_rounded), title: Text(h.name), subtitle: Text(h.host), onTap: () => setState(() => _host.text = h.host)),
              const SizedBox(height: 10),
              TextField(controller: _host, keyboardType: TextInputType.url, decoration: const InputDecoration(labelText: 'Hub address', hintText: 'e.g. 192.168.43.1')),
              const SizedBox(height: 10),
              TextField(
                controller: _code,
                keyboardType: TextInputType.number,
                maxLength: 6,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: const InputDecoration(labelText: '6-digit code shown on the hub'),
              ),
              FilledButton(
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
                onPressed: () {
                  if (_host.text.trim().isEmpty || _code.text.length != 6) {
                    showSnack(context, 'Enter the hub address and its 6-digit code', error: true);
                    return;
                  }
                  lan.join(_host.text, _code.text);
                },
                child: const Text('Connect'),
              ),
            ]),
          LanMode.hub => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              HeroPanel(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('This device is the hub', style: t.textTheme.labelLarge?.copyWith(color: Colors.white)),
                  const SizedBox(height: 6),
                  Text(st.code ?? '', style: t.textTheme.displaySmall?.copyWith(color: Colors.white, letterSpacing: 6, fontFeatures: const [FontFeature.tabularFigures()])),
                  Text('Code to connect · address ${st.addresses.isEmpty ? 'not on a network' : st.addresses.join(' or ')}', style: t.textTheme.bodyMedium?.copyWith(color: Colors.white)),
                ]),
              ),
              SectionHeader('Connected devices', count: st.peers.length, padding: const EdgeInsets.fromLTRB(4, 16, 0, 8)),
              if (st.peers.isEmpty) const Text('No device has connected yet.'),
              for (final p in st.peers.values)
                ListTile(
                  leading: Icon(Icons.tablet_android_rounded, color: DateTime.now().difference(p.seen).inSeconds < 30 ? Colors.green.shade600 : t.colorScheme.outline),
                  title: Text(p.name),
                  subtitle: Text('Last shared ${fmtAgo(p.seen)}'),
                ),
              const SizedBox(height: 12),
              OutlinedButton(onPressed: () => lan.stop(), child: const Text('Stop sharing')),
            ]),
          LanMode.peer => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Card(
                child: ListTile(
                  leading: Icon(st.error == null && st.lastExchange != null ? Icons.link_rounded : Icons.link_off_rounded,
                      color: st.error == null && st.lastExchange != null ? Colors.green.shade600 : t.colorScheme.error),
                  title: Text('Connected to ${st.host}'),
                  subtitle: Text(st.lastExchange == null ? 'Connecting…' : 'Last shared ${fmtAgo(st.lastExchange)} · every 8 seconds'),
                ),
              ),
              const SizedBox(height: 8),
              FilledButton.tonal(onPressed: () => lan.exchangeNow(), child: const Text('Share now')),
              const SizedBox(height: 8),
              OutlinedButton(onPressed: () => lan.stop(), child: const Text('Disconnect')),
            ]),
        },
        const SizedBox(height: 20),
        Text('Data travels only inside your hotspot or Wi-Fi and is signed with the hub\'s code. Use a password-protected hotspot.',
            style: t.textTheme.labelSmall?.copyWith(color: t.colorScheme.onSurfaceVariant)),
      ]),
    );
  }
}
