import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/opd/registration.dart';
import '../auth/auth_controller.dart';
import '../db/app_database.dart';
import '../providers.dart';
import 'lan_store.dart';

/// Clinic devices sharing data over a phone hotspot or local Wi-Fi, with no internet.
///
/// One device is the hub (it runs a small HTTP endpoint and announces itself on the network); the
/// others join it with the hub's 6-digit code. Every request and answer is signed with a key derived
/// from that code and the hospital's server address, so only paired devices of the same hospital are
/// accepted. Peers exchange their full shareable state every few seconds (see [LanStore]).
const lanPort = 47820;
const lanBeaconPort = 47821;
const _proto = 'hms-lan-1';

String lanSign(String key, List<int> body) => Hmac(sha256, utf8.encode(key)).convert(body).toString();
String lanKey(String code, String serverUrl) => sha256.convert(utf8.encode('$_proto|$code|${serverUrl.toLowerCase()}')).toString();

class LanPeerInfo {
  LanPeerInfo(this.device, this.name, this.seen);
  final int device;
  final String name;
  final DateTime seen;
}

class LanHubAnnounce {
  LanHubAnnounce(this.host, this.port, this.name);
  final String host;
  final int port;
  final String name;
}

enum LanMode { off, hub, peer }

class LanState {
  const LanState({this.mode = LanMode.off, this.code, this.host, this.addresses = const [], this.peers = const {}, this.lastExchange, this.lastChanged = 0, this.error, this.discovered = const []});
  final LanMode mode;
  final String? code;

  /// Peer: the hub's address.
  final String? host;

  /// Hub: this device's addresses on the local network.
  final List<String> addresses;
  final Map<int, LanPeerInfo> peers;
  final DateTime? lastExchange;
  final int lastChanged;
  final String? error;
  final List<LanHubAnnounce> discovered;

  LanState copyWith({LanMode? mode, String? code, String? host, List<String>? addresses, Map<int, LanPeerInfo>? peers, DateTime? lastExchange, int? lastChanged, String? error, bool clearError = false, List<LanHubAnnounce>? discovered}) =>
      LanState(
        mode: mode ?? this.mode,
        code: code ?? this.code,
        host: host ?? this.host,
        addresses: addresses ?? this.addresses,
        peers: peers ?? this.peers,
        lastExchange: lastExchange ?? this.lastExchange,
        lastChanged: lastChanged ?? this.lastChanged,
        error: clearError ? null : (error ?? this.error),
        discovered: discovered ?? this.discovered,
      );
}

/// The hub's HTTP endpoint. Separate from the controller so tests can run it on localhost.
class LanHubServer {
  LanHubServer({required this.store, required this.key, required this.deviceName, required this.onPeer, required this.onChanged});
  final LanStore store;
  final String key;
  final String deviceName;
  final void Function(LanPeerInfo) onPeer;
  final void Function(int changed) onChanged;
  HttpServer? _server;

  int? get port => _server?.port;

  Future<void> start({int port = lanPort, InternetAddress? address}) async {
    _server = await HttpServer.bind(address ?? InternetAddress.anyIPv4, port, shared: true);
    _server!.listen(_handle, onError: (_) {});
  }

  Future<void> stop() async {
    await _server?.close(force: true);
    _server = null;
  }

  Future<void> _handle(HttpRequest req) async {
    final res = req.response;
    try {
      if (req.method != 'POST' || req.uri.path != '/lan/v1/exchange') {
        res.statusCode = HttpStatus.notFound;
        return;
      }
      final body = await req.fold<List<int>>(<int>[], (a, b) => a..addAll(b));
      if (body.length > 8 * 1024 * 1024 || req.headers.value('x-hms-sig') != lanSign(key, body)) {
        res.statusCode = HttpStatus.forbidden; // wrong code or another hospital
        return;
      }
      final j = jsonDecode(utf8.decode(body)) as Map<String, dynamic>;
      final snap = Map<String, dynamic>.from(j['snapshot'] as Map);
      onPeer(LanPeerInfo((snap['device'] as num).toInt(), '${j['name'] ?? 'Device'}', DateTime.now()));
      final changed = await store.merge(snap);
      if (changed > 0) onChanged(changed);
      final out = utf8.encode(jsonEncode({'name': deviceName, 'snapshot': await store.snapshot()}));
      res.headers.set('x-hms-sig', lanSign(key, out));
      res.headers.contentType = ContentType.json;
      res.add(out);
    } catch (_) {
      res.statusCode = HttpStatus.badRequest;
    } finally {
      await res.close();
    }
  }
}

/// One exchange with a hub: send our snapshot, merge theirs. Returns rows changed here.
Future<int> lanExchange({required LanStore store, required String key, required String host, int port = lanPort, required String deviceName, Duration timeout = const Duration(seconds: 8)}) async {
  final client = HttpClient()..connectionTimeout = timeout;
  try {
    final body = utf8.encode(jsonEncode({'name': deviceName, 'snapshot': await store.snapshot()}));
    final req = await client.post(host, port, '/lan/v1/exchange').timeout(timeout);
    req.headers.set('x-hms-sig', lanSign(key, body));
    req.headers.contentType = ContentType.json;
    req.add(body);
    final res = await req.close().timeout(timeout);
    final out = await res.fold<List<int>>(<int>[], (a, b) => a..addAll(b)).timeout(timeout);
    if (res.statusCode == HttpStatus.forbidden) throw const LanException('The hub refused this device. Check the 6-digit code.');
    if (res.statusCode != 200) throw LanException('Hub answered ${res.statusCode}.');
    if (res.headers.value('x-hms-sig') != lanSign(key, out)) throw const LanException('The answer was not signed by the hub. Check the code.');
    final j = jsonDecode(utf8.decode(out)) as Map<String, dynamic>;
    return store.merge(Map<String, dynamic>.from(j['snapshot'] as Map));
  } on SocketException {
    throw const LanException('Cannot reach the hub. Are both devices on the same hotspot or Wi-Fi?');
  } on TimeoutException {
    throw const LanException('The hub did not answer in time.');
  } finally {
    client.close(force: true);
  }
}

class LanException implements Exception {
  const LanException(this.message);
  final String message;
  @override
  String toString() => message;
}

class LanController extends StateNotifier<LanState> {
  LanController({required this.adb, required this.serverUrl, required this.deviceName, required this.onChanged}) : super(const LanState()) {
    _restore();
  }

  final AppDatabase adb;
  final String serverUrl;
  final String deviceName;
  final void Function() onChanged;

  late final store = LanStore(adb);
  LanHubServer? _hub;
  Timer? _timer;
  RawDatagramSocket? _beacon;
  RawDatagramSocket? _listen;
  bool _busy = false;

  Future<void> _restore() async {
    final raw = await adb.getMeta('lan_settings');
    if (raw == null) return;
    final j = jsonDecode(raw) as Map<String, dynamic>;
    if (j['mode'] == 'hub') await startHub(code: j['code'] as String?);
    if (j['mode'] == 'peer' && j['host'] != null && j['code'] != null) await join(j['host'] as String, j['code'] as String);
  }

  Future<void> _persist() => adb.setMeta('lan_settings', jsonEncode({'mode': state.mode.name, 'code': state.code, 'host': state.host}));

  String get _key => lanKey(state.code ?? '', serverUrl);

  Future<void> startHub({String? code}) async {
    await stop(persist: false);
    final c = code ?? (100000 + Random.secure().nextInt(900000)).toString();
    state = LanState(mode: LanMode.hub, code: c, addresses: await localAddresses());
    try {
      _hub = LanHubServer(
        store: store,
        key: _key,
        deviceName: deviceName,
        onPeer: (p) => state = state.copyWith(peers: {...state.peers, p.device: p}, lastExchange: DateTime.now()),
        onChanged: (n) {
          state = state.copyWith(lastChanged: n);
          onChanged();
        },
      );
      await _hub!.start();
      await _startBeacon();
      state = state.copyWith(clearError: true);
    } catch (e) {
      state = state.copyWith(error: 'Could not start sharing: $e');
    }
    await _persist();
  }

  Future<void> join(String host, String code) async {
    await stop(persist: false);
    state = LanState(mode: LanMode.peer, host: host.trim(), code: code.trim());
    await _persist();
    _timer = Timer.periodic(const Duration(seconds: 8), (_) => exchangeNow());
    await exchangeNow();
  }

  Future<void> exchangeNow() async {
    if (state.mode != LanMode.peer || _busy) return;
    _busy = true;
    try {
      final n = await lanExchange(store: store, key: _key, host: state.host!, deviceName: deviceName);
      state = state.copyWith(lastExchange: DateTime.now(), lastChanged: n, clearError: true);
      if (n > 0) onChanged();
    } on LanException catch (e) {
      state = state.copyWith(error: e.message);
    } catch (e) {
      state = state.copyWith(error: 'Sharing failed: $e');
    } finally {
      _busy = false;
    }
  }

  Future<void> stop({bool persist = true}) async {
    _timer?.cancel();
    _timer = null;
    _beacon?.close();
    _beacon = null;
    await _hub?.stop();
    _hub = null;
    if (persist) {
      state = const LanState();
      await _persist();
    }
  }

  // ------------------------------------------------------------------ discovery
  Future<void> _startBeacon() async {
    try {
      _beacon = await RawDatagramSocket.bind(InternetAddress.anyIPv4, 0)..broadcastEnabled = true;
      final tag = sha256.convert(utf8.encode(serverUrl.toLowerCase())).toString().substring(0, 12);
      final msg = utf8.encode(jsonEncode({'p': _proto, 'port': _hub!.port, 'name': deviceName, 'tag': tag}));
      _timer = Timer.periodic(const Duration(seconds: 3), (_) {
        try {
          _beacon?.send(msg, InternetAddress('255.255.255.255'), lanBeaconPort);
        } catch (_) {}
      });
    } catch (_) {
      // Broadcast not allowed on this network: peers type the hub address instead.
    }
  }

  /// Listen for hubs of this hospital announcing themselves (for [discoverySeconds]).
  Future<void> discover({int discoverySeconds = 6}) async {
    _listen?.close();
    final tag = sha256.convert(utf8.encode(serverUrl.toLowerCase())).toString().substring(0, 12);
    final found = <String, LanHubAnnounce>{};
    try {
      _listen = await RawDatagramSocket.bind(InternetAddress.anyIPv4, lanBeaconPort, reuseAddress: true);
      _listen!.listen((e) {
        if (e != RawSocketEvent.read) return;
        final d = _listen?.receive();
        if (d == null) return;
        try {
          final j = jsonDecode(utf8.decode(d.data)) as Map<String, dynamic>;
          if (j['p'] != _proto || j['tag'] != tag) return;
          found[d.address.address] = LanHubAnnounce(d.address.address, (j['port'] as num).toInt(), '${j['name']}');
          state = state.copyWith(discovered: found.values.toList());
        } catch (_) {}
      });
      await Future<void>.delayed(Duration(seconds: discoverySeconds));
    } catch (_) {
      // ignore: discovery is a convenience
    } finally {
      _listen?.close();
      _listen = null;
    }
  }

  static Future<List<String>> localAddresses() async {
    try {
      final list = await NetworkInterface.list(type: InternetAddressType.IPv4);
      return [for (final i in list) for (final a in i.addresses) if (!a.isLoopback) a.address];
    } catch (_) {
      return const [];
    }
  }

  @override
  void dispose() {
    stop(persist: false);
    _listen?.close();
    super.dispose();
  }
}

final lanProvider = StateNotifierProvider<LanController, LanState>((ref) {
  final session = ref.watch(sessionProvider);
  return LanController(
    adb: ref.watch(databaseProvider),
    serverUrl: ref.watch(authControllerProvider.select((s) => s is SignedInBase ? s.serverUrl : '')),
    deviceName: session.staff.displayName,
    onChanged: () => ref.read(dataRevisionProvider.notifier).state++,
  );
});

/// Helper for tests and diagnostics.
Future<int> devicePrefixOf(AppDatabase adb) => RegistrationRepository.devicePrefix(adb.db);
