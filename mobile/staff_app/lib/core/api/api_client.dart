import 'package:dio/dio.dart';

import '../config.dart';

/// Why a request failed, so callers can react (stay offline, ask to sign in again, show the message).
enum ApiErrorKind { offline, unauthorized, forbidden, validation, server, unknown }

class ApiException implements Exception {
  ApiException(this.kind, this.message, {this.code, this.status});

  final ApiErrorKind kind;
  final String message;
  final String? code;
  final int? status;

  bool get isOffline => kind == ApiErrorKind.offline;

  @override
  String toString() => message;
}

/// Thin wrapper over the hospital's api/v1 endpoints.
class ApiClient {
  ApiClient({required String baseUrl, String? token, Dio? dio})
      : _dio = dio ??
            Dio(BaseOptions(
              connectTimeout: AppConfig.connectTimeout,
              receiveTimeout: AppConfig.receiveTimeout,
              sendTimeout: AppConfig.connectTimeout,
              contentType: Headers.jsonContentType,
              responseType: ResponseType.json,
            )) {
    _dio.options.baseUrl = '${normalizeServerUrl(baseUrl)}/api/v1/';
    if (token != null) setToken(token);
  }

  final Dio _dio;

  /// "hms.example.com/" -> "https://hms.example.com"
  static String normalizeServerUrl(String input) {
    var u = input.trim();
    if (u.isEmpty) return u;
    if (!u.startsWith('http://') && !u.startsWith('https://')) u = 'https://$u';
    while (u.endsWith('/')) {
      u = u.substring(0, u.length - 1);
    }
    return u;
  }

  void setToken(String? token) {
    if (token == null) {
      _dio.options.headers.remove('X-Auth-Token');
      _dio.options.headers.remove('Authorization');
    } else {
      // X-Auth-Token survives proxies that strip Authorization; the server accepts either.
      _dio.options.headers['X-Auth-Token'] = token;
      _dio.options.headers['Authorization'] = 'Bearer $token';
    }
  }

  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? query}) =>
      _send(() => _dio.get(path, queryParameters: query));

  Future<Map<String, dynamic>> post(String path, Object? body) => _send(() => _dio.post(path, data: body));

  /// GET with an ETag. Returns null when the server says nothing changed (304).
  Future<({Map<String, dynamic> body, String? etag})?> getIfChanged(String path, String? etag) async {
    try {
      final r = await _dio.get(path,
          options: Options(
            headers: {'If-None-Match': ?etag},
            validateStatus: (s) => s != null && (s == 304 || (s >= 200 && s < 300)),
          ));
      if (r.statusCode == 304) return null;
      return (body: _asMap(r.data), etag: r.headers.value('etag'));
    } on DioException catch (e) {
      throw _map(e);
    }
  }

  /// Download a file (with the auth token) to [savePath].
  Future<void> download(String path, String savePath, {Map<String, dynamic>? query}) async {
    try {
      await _dio.download(path, savePath, queryParameters: query, options: Options(responseType: ResponseType.bytes));
    } on DioException catch (e) {
      throw _map(e);
    }
  }

  Future<Map<String, dynamic>> _send(Future<Response<dynamic>> Function() call) async {
    try {
      final r = await call();
      return _asMap(r.data);
    } on DioException catch (e) {
      throw _map(e);
    }
  }

  Map<String, dynamic> _asMap(Object? data) {
    if (data is Map<String, dynamic>) return data;
    if (data is Map) return Map<String, dynamic>.from(data);
    // An HTML page here usually means a wrong server address or a login redirect.
    throw ApiException(ApiErrorKind.server, 'The server sent an unexpected reply. Check the hospital address.');
  }

  ApiException _map(DioException e) {
    switch (e.type) {
      case DioExceptionType.connectionError:
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
        return ApiException(ApiErrorKind.offline, 'Cannot reach the hospital server.');
      default:
        break;
    }
    final status = e.response?.statusCode;
    String? code;
    String? message;
    final data = e.response?.data;
    if (data is Map && data['error'] is Map) {
      code = data['error']['code'] as String?;
      message = data['error']['message'] as String?;
    }
    final kind = switch (status) {
      401 => ApiErrorKind.unauthorized,
      403 => ApiErrorKind.forbidden,
      422 || 429 => ApiErrorKind.validation,
      int s when s >= 500 => ApiErrorKind.server,
      404 when code == null => ApiErrorKind.server,
      _ => ApiErrorKind.unknown,
    };
    message ??= switch (kind) {
      ApiErrorKind.server => status == 404
          ? 'The mobile API was not found on this server. Check the address or update the server.'
          : 'The server had a problem. Try again shortly.',
      ApiErrorKind.unauthorized => 'Your session has expired. Sign in again.',
      _ => 'Request failed${status != null ? ' ($status)' : ''}.',
    };
    return ApiException(kind, message, code: code, status: status);
  }
}
