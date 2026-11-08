import 'dart:math' as math;

import 'package:flutter/material.dart';

import 'motion.dart';

class ChartSeries {
  const ChartSeries({required this.label, required this.points, this.color});
  final String label;

  /// (x, y). x is any increasing number (e.g. age in months, or milliseconds since epoch).
  final List<(double, double)> points;
  final Color? color;
}

/// Small dependency-free line chart with min/max labels, dots, and a draw-in animation.
class LineChart extends StatelessWidget {
  const LineChart({super.key, required this.series, this.height = 160, this.xLabel, this.yUnit, this.formatX});
  final List<ChartSeries> series;
  final double height;
  final String? xLabel;
  final String? yUnit;
  final String Function(double x)? formatX;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final palette = [cs.primary, cs.secondary, cs.tertiary, cs.error];
    final s = [for (final (i, x) in series.indexed) ChartSeries(label: x.label, points: x.points, color: x.color ?? palette[i % palette.length])];
    final labelStyle = Theme.of(context).textTheme.labelSmall!;
    _ChartPainter painter(double t) => _ChartPainter(s, t, cs, labelStyle, yUnit, formatX);
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      SizedBox(
        height: height,
        width: double.infinity,
        child: Motion.enabled(context)
            ? TweenAnimationBuilder<double>(
                tween: Tween(begin: 0, end: 1),
                duration: Motion.slow,
                curve: Motion.emphasized,
                builder: (_, t, _) => CustomPaint(painter: painter(t)),
              )
            : CustomPaint(painter: painter(1)),
      ),
      if (s.length > 1 || xLabel != null)
        Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Wrap(spacing: 12, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
            if (s.length > 1)
              for (final x in s)
                Row(mainAxisSize: MainAxisSize.min, children: [
                  Container(width: 10, height: 10, decoration: BoxDecoration(color: x.color, shape: BoxShape.circle)),
                  const SizedBox(width: 4),
                  Text(x.label, style: Theme.of(context).textTheme.labelSmall),
                ]),
            if (xLabel != null) Text(xLabel!, style: Theme.of(context).textTheme.labelSmall?.copyWith(color: cs.onSurfaceVariant)),
          ]),
        ),
    ]);
  }
}

class _ChartPainter extends CustomPainter {
  _ChartPainter(this.series, this.t, this.cs, this.label, this.unit, this.formatX);
  final List<ChartSeries> series;
  final double t;
  final ColorScheme cs;
  final TextStyle label;
  final String? unit;
  final String Function(double)? formatX;

  @override
  void paint(Canvas canvas, Size size) {
    final all = [for (final s in series) ...s.points];
    if (all.isEmpty) return;
    var minX = all.map((p) => p.$1).reduce(math.min), maxX = all.map((p) => p.$1).reduce(math.max);
    var minY = all.map((p) => p.$2).reduce(math.min), maxY = all.map((p) => p.$2).reduce(math.max);
    if (maxX == minX) {
      minX -= 1;
      maxX += 1;
    }
    final padY = (maxY - minY).abs() < 1e-9 ? (maxY.abs() * 0.1 + 1) : (maxY - minY) * 0.12;
    final nonNegative = minY >= 0;
    _integers = all.every((p) => p.$2 == p.$2.roundToDouble());
    minY -= padY;
    maxY += padY;
    // Counts and other non-negative data never show a negative axis; whole numbers get whole-number labels.
    if (nonNegative && minY < 0) minY = 0;
    // 3 gridline steps: make the range a multiple of 3 so every label is a whole number.
    if (_integers) maxY = minY + (((maxY - minY) / 3).ceil().clamp(1, 1 << 30) * 3).toDouble();

    const left = 36.0, bottom = 18.0, top = 6.0, right = 8.0;
    final w = size.width - left - right, h = size.height - top - bottom;
    Offset at((double, double) p) => Offset(left + (p.$1 - minX) / (maxX - minX) * w, top + h - (p.$2 - minY) / (maxY - minY) * h);

    final grid = Paint()
      ..color = cs.outlineVariant.withValues(alpha: 0.6)
      ..strokeWidth = 1;
    for (var i = 0; i <= 3; i++) {
      final y = top + h * i / 3;
      canvas.drawLine(Offset(left, y), Offset(left + w, y), grid);
      final v = maxY - (maxY - minY) * i / 3;
      _text(canvas, _fmt(v), Offset(0, y - 7), left - 4, TextAlign.right);
    }
    if (formatX != null) {
      _text(canvas, formatX!(minX), Offset(left, top + h + 3), 80, TextAlign.left);
      _text(canvas, formatX!(maxX), Offset(left + w - 80, top + h + 3), 80, TextAlign.right);
    }

    for (final s in series) {
      if (s.points.isEmpty) continue;
      final pts = [...s.points]..sort((a, b) => a.$1.compareTo(b.$1));
      final color = s.color ?? cs.primary;
      final path = Path()..moveTo(at(pts.first).dx, at(pts.first).dy);
      for (final p in pts.skip(1)) {
        final o = at(p);
        path.lineTo(o.dx, o.dy);
      }
      // Draw-in animation: clip to the revealed width.
      canvas.save();
      canvas.clipRect(Rect.fromLTWH(0, 0, left + w * t + 6, size.height));
      final fill = Path.from(path)
        ..lineTo(at(pts.last).dx, top + h)
        ..lineTo(at(pts.first).dx, top + h)
        ..close();
      canvas.drawPath(
          fill,
          Paint()
            ..shader = LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [color.withValues(alpha: 0.18), color.withValues(alpha: 0.0)])
                .createShader(Rect.fromLTWH(left, top, w, h)));
      canvas.drawPath(
          path,
          Paint()
            ..color = color
            ..style = PaintingStyle.stroke
            ..strokeWidth = 2.4
            ..strokeJoin = StrokeJoin.round
            ..strokeCap = StrokeCap.round);
      for (final p in pts) {
        final o = at(p);
        canvas.drawCircle(o, 4, Paint()..color = cs.surface);
        canvas.drawCircle(o, 3, Paint()..color = color);
      }
      canvas.restore();
    }
  }

  bool _integers = false;

  String _fmt(double v) => _integers
      ? v.round().toString()
      : v.abs() >= 100 ? v.round().toString() : v.toStringAsFixed(v.abs() >= 10 ? 0 : 1);

  void _text(Canvas c, String s, Offset o, double width, TextAlign align) {
    final tp = TextPainter(text: TextSpan(text: s, style: label.copyWith(color: cs.onSurfaceVariant, fontSize: 10)), textAlign: align, textDirection: TextDirection.ltr)
      ..layout(minWidth: width, maxWidth: width);
    tp.paint(c, o);
  }

  @override
  bool shouldRepaint(_ChartPainter old) => old.t != t || old.series != series;
}
