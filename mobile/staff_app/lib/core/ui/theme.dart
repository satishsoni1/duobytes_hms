import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../config.dart';

Color? parseHexColor(String? hex) {
  if (hex == null) return null;
  var h = hex.trim().replaceFirst('#', '');
  if (h.length == 3) h = h.split('').map((c) => '$c$c').join();
  if (h.length != 6) return null;
  final v = int.tryParse(h, radix: 16);
  return v == null ? null : Color(0xFF000000 | v);
}

/// Shape and spacing tokens shared by every screen.
class Radii {
  static const sm = 10.0;
  static const md = 16.0;
  static const lg = 22.0;
  static const xl = 28.0;
}

/// A hospital colour from settings is used only if it is a real colour. Greys/black (the default
/// `#424242`), near-white and very dark values fall back to the brand teal.
Color usableBrandColor(Color? c) {
  if (c == null) return AppConfig.brandColor;
  final h = HSLColor.fromColor(c);
  return (h.saturation < 0.25 || h.lightness < 0.18 || h.lightness > 0.75) ? AppConfig.brandColor : c;
}

Color _hsl(double hue, double sat, double light) => HSLColor.fromAHSL(1, hue % 360, sat.clamp(0.0, 1.0), light.clamp(0.0, 1.0)).toColor();

/// Light, calm healthcare palette built from the brand hue: white/mint surfaces, medical teal,
/// a soft blue accent, green for "done", coral for alerts, and teal-navy text instead of black.
ColorScheme healthcareScheme(Color seed) {
  final hue = HSLColor.fromColor(seed).hue;
  final blue = hue + 22;
  const green = 152.0;
  return ColorScheme(
    brightness: Brightness.light,
    primary: _hsl(hue, 0.66, 0.33),
    onPrimary: Colors.white,
    primaryContainer: _hsl(hue, 0.58, 0.90),
    onPrimaryContainer: _hsl(hue, 0.72, 0.20),
    primaryFixed: _hsl(hue, 0.58, 0.90),
    primaryFixedDim: _hsl(hue, 0.5, 0.80),
    onPrimaryFixed: _hsl(hue, 0.72, 0.16),
    onPrimaryFixedVariant: _hsl(hue, 0.66, 0.28),
    secondary: _hsl(blue, 0.58, 0.42),
    onSecondary: Colors.white,
    secondaryContainer: _hsl(blue, 0.75, 0.93),
    onSecondaryContainer: _hsl(blue, 0.6, 0.24),
    tertiary: _hsl(green, 0.52, 0.36),
    onTertiary: Colors.white,
    tertiaryContainer: _hsl(green - 4, 0.55, 0.90),
    onTertiaryContainer: _hsl(green, 0.62, 0.20),
    error: const Color(0xFFD64545),
    onError: Colors.white,
    errorContainer: const Color(0xFFFDECEC),
    onErrorContainer: const Color(0xFF8E1F1F),
    surface: _hsl(hue, 0.40, 0.985),
    onSurface: _hsl(hue, 0.45, 0.15),
    onSurfaceVariant: _hsl(hue, 0.18, 0.40),
    surfaceDim: _hsl(hue, 0.25, 0.90),
    surfaceBright: Colors.white,
    surfaceContainerLowest: Colors.white,
    surfaceContainerLow: _hsl(hue, 0.38, 0.965),
    surfaceContainer: _hsl(hue, 0.34, 0.950),
    surfaceContainerHigh: _hsl(hue, 0.30, 0.932),
    surfaceContainerHighest: _hsl(hue, 0.27, 0.910),
    outline: _hsl(hue, 0.15, 0.62),
    outlineVariant: _hsl(hue, 0.26, 0.87),
    shadow: _hsl(hue, 0.4, 0.2),
    scrim: _hsl(hue, 0.4, 0.1),
    inverseSurface: _hsl(hue, 0.35, 0.20),
    onInverseSurface: _hsl(hue, 0.3, 0.95),
    inversePrimary: _hsl(hue, 0.6, 0.75),
    surfaceTint: _hsl(hue, 0.66, 0.33),
  );
}

/// The app always uses the light healthcare theme ([brightness] is kept for API compatibility).
ThemeData buildTheme({Color? seed, Brightness brightness = Brightness.light}) {
  final scheme = healthcareScheme(usableBrandColor(seed));

  final base = ThemeData(colorScheme: scheme, useMaterial3: true, brightness: Brightness.light);
  final text = base.textTheme.copyWith(
    displaySmall: base.textTheme.displaySmall?.copyWith(fontWeight: FontWeight.w700, letterSpacing: -0.8),
    headlineMedium: base.textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.w700, letterSpacing: -0.6),
    headlineSmall: base.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700, letterSpacing: -0.4),
    titleLarge: base.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700, letterSpacing: -0.3),
    titleMedium: base.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600, letterSpacing: -0.1),
    titleSmall: base.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w600),
    labelLarge: base.textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600, letterSpacing: 0.1),
  );

  final roundedMd = RoundedRectangleBorder(borderRadius: BorderRadius.circular(Radii.md));
  final scaffoldBg = Color.alphaBlend(scheme.primary.withValues(alpha: 0.025), scheme.surface);

  return base.copyWith(
    textTheme: text,
    scaffoldBackgroundColor: scaffoldBg,
    visualDensity: VisualDensity.standard,
    splashFactory: InkSparkle.splashFactory,
    pageTransitionsTheme: const PageTransitionsTheme(builders: {
      TargetPlatform.android: FadeForwardsPageTransitionsBuilder(),
      TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
    }),
    appBarTheme: AppBarTheme(
      centerTitle: false,
      backgroundColor: scaffoldBg,
      surfaceTintColor: Colors.transparent,
      scrolledUnderElevation: 0,
      elevation: 0,
      titleTextStyle: text.titleLarge?.copyWith(color: scheme.onSurface),
      systemOverlayStyle: SystemUiOverlayStyle.dark,
    ),
    cardTheme: CardThemeData(
      elevation: 0,
      margin: EdgeInsets.zero,
      color: scheme.surfaceContainerLowest,
      shadowColor: scheme.shadow.withValues(alpha: 0.08),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Radii.lg),
        side: BorderSide(color: scheme.outlineVariant.withValues(alpha: 0.55)),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: scheme.surfaceContainerLow,
      isDense: false,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(Radii.md), borderSide: BorderSide.none),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(Radii.md), borderSide: BorderSide(color: scheme.outlineVariant.withValues(alpha: 0.4))),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(Radii.md), borderSide: BorderSide(color: scheme.primary, width: 1.6)),
      errorBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(Radii.md), borderSide: BorderSide(color: scheme.error)),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(minimumSize: const Size(64, 46), shape: roundedMd, textStyle: text.labelLarge),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(minimumSize: const Size(64, 46), shape: roundedMd, side: BorderSide(color: scheme.outlineVariant), textStyle: text.labelLarge),
    ),
    textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(shape: roundedMd, textStyle: text.labelLarge)),
    floatingActionButtonTheme: FloatingActionButtonThemeData(
      backgroundColor: scheme.primary,
      foregroundColor: scheme.onPrimary,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Radii.lg)),
      elevation: 2,
      highlightElevation: 4,
      extendedTextStyle: text.labelLarge,
    ),
    chipTheme: base.chipTheme.copyWith(
      shape: const StadiumBorder(),
      side: BorderSide(color: scheme.outlineVariant.withValues(alpha: 0.6)),
      backgroundColor: scheme.surfaceContainerLow,
      labelStyle: text.labelMedium,
    ),
    navigationBarTheme: NavigationBarThemeData(
      height: 68,
      elevation: 0,
      backgroundColor: scheme.surfaceContainerLowest,
      surfaceTintColor: Colors.transparent,
      indicatorColor: scheme.primaryContainer,
      indicatorShape: const StadiumBorder(),
      iconTheme: WidgetStateProperty.resolveWith((s) => IconThemeData(
            size: 24,
            color: s.contains(WidgetState.selected) ? scheme.onPrimaryContainer : scheme.onSurfaceVariant,
          )),
      labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
      labelTextStyle: WidgetStateProperty.resolveWith((s) => text.labelMedium?.copyWith(
            fontWeight: s.contains(WidgetState.selected) ? FontWeight.w700 : FontWeight.w500,
            color: s.contains(WidgetState.selected) ? scheme.onSurface : scheme.onSurfaceVariant,
          )),
    ),
    tabBarTheme: TabBarThemeData(
      dividerColor: Colors.transparent,
      indicatorSize: TabBarIndicatorSize.tab,
      indicator: BoxDecoration(color: scheme.primaryContainer, borderRadius: BorderRadius.circular(40)),
      labelColor: scheme.onPrimaryContainer,
      unselectedLabelColor: scheme.onSurfaceVariant,
      labelStyle: text.labelLarge,
      unselectedLabelStyle: text.labelLarge?.copyWith(fontWeight: FontWeight.w500),
      labelPadding: const EdgeInsets.symmetric(horizontal: 16),
      splashBorderRadius: BorderRadius.circular(40),
      overlayColor: WidgetStatePropertyAll(scheme.primary.withValues(alpha: 0.06)),
    ),
    bottomSheetTheme: BottomSheetThemeData(
      showDragHandle: true,
      backgroundColor: scheme.surface,
      surfaceTintColor: Colors.transparent,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(Radii.xl))),
    ),
    dialogTheme: DialogThemeData(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Radii.xl)),
      surfaceTintColor: Colors.transparent,
      backgroundColor: scheme.surface,
    ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Radii.md)),
      insetPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
    ),
    listTileTheme: ListTileThemeData(
      contentPadding: const EdgeInsets.symmetric(horizontal: 16),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Radii.md)),
    ),
    dividerTheme: DividerThemeData(color: scheme.outlineVariant.withValues(alpha: 0.5), space: 1),
    progressIndicatorTheme: ProgressIndicatorThemeData(color: scheme.primary, linearTrackColor: scheme.primaryContainer),
    segmentedButtonTheme: SegmentedButtonThemeData(style: SegmentedButton.styleFrom(shape: const StadiumBorder())),
  );
}

/// Colors for clinical warnings.
extension ClinicalColors on ColorScheme {
  Color get allergyBg => errorContainer;
  Color get allergyFg => onErrorContainer;

  /// Hero header gradient: medical teal to a fresh aqua-blue. Never dark enough to read as black.
  LinearGradient get heroGradient {
    final hue = HSLColor.fromColor(primary).hue;
    return LinearGradient(
      begin: Alignment.topLeft,
      end: Alignment.bottomRight,
      colors: [_hsl(hue - 4, 0.62, 0.34), _hsl(hue + 8, 0.58, 0.42), _hsl(hue + 22, 0.62, 0.50)],
    );
  }

  /// A lighter brand accent (hue-shifted primary) for decorative highlights.
  Color get brandGlow {
    final h = HSLColor.fromColor(primary);
    return h.withHue((h.hue + 24) % 360).withLightness((h.lightness + 0.22).clamp(0.0, 0.8)).toColor();
  }
}
