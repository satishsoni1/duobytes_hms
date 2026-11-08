import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Motion tokens. Everything honours the OS "reduce motion" setting via [Motion.enabled].
class Motion {
  static const fast = Duration(milliseconds: 180);
  static const medium = Duration(milliseconds: 320);
  static const slow = Duration(milliseconds: 520);
  static const emphasized = Curves.easeOutCubic;
  static const spring = Curves.easeOutBack;

  static bool enabled(BuildContext context) => !(MediaQuery.maybeDisableAnimationsOf(context) ?? false);
}

/// Fades and slides a child in once. Pass [index] to stagger items in a list.
class FadeSlideIn extends StatefulWidget {
  const FadeSlideIn({super.key, required this.child, this.index = 0, this.offset = const Offset(0, 24), this.duration = Motion.slow});

  final Widget child;
  final int index;
  final Offset offset;
  final Duration duration;

  @override
  State<FadeSlideIn> createState() => _FadeSlideInState();
}

class _FadeSlideInState extends State<FadeSlideIn> with SingleTickerProviderStateMixin {
  static const _step = 45; // ms between staggered items
  static const _maxSteps = 8; // later items start together so long lists don't lag

  late final AnimationController _c;
  late final Animation<double> _t;

  @override
  void initState() {
    super.initState();
    final delay = _step * widget.index.clamp(0, _maxSteps);
    final total = widget.duration.inMilliseconds + delay;
    _c = AnimationController(vsync: this, duration: Duration(milliseconds: total));
    _t = CurvedAnimation(parent: _c, curve: Interval(delay / total, 1, curve: Motion.emphasized));
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (!Motion.enabled(context)) {
      _c.value = 1;
    } else if (!_c.isAnimating && _c.value == 0) {
      _c.forward();
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
        animation: _t,
        child: widget.child,
        builder: (_, child) => Opacity(
          opacity: _t.value,
          child: Transform.translate(offset: widget.offset * (1 - _t.value), child: child),
        ),
      );
}

/// Shrinks slightly while pressed, like modern native apps. Wrap tappable cards with it.
class Pressable extends StatefulWidget {
  const Pressable({super.key, required this.child, this.onTap, this.borderRadius = 22, this.haptic = false});
  final Widget child;
  final VoidCallback? onTap;
  final double borderRadius;
  final bool haptic;

  @override
  State<Pressable> createState() => _PressableState();
}

class _PressableState extends State<Pressable> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return AnimatedScale(
      scale: _down && Motion.enabled(context) ? 0.97 : 1,
      duration: Motion.fast,
      curve: Curves.easeOut,
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          borderRadius: BorderRadius.circular(widget.borderRadius),
          onTap: widget.onTap == null
              ? null
              : () {
                  if (widget.haptic) HapticFeedback.selectionClick();
                  widget.onTap!();
                },
          onHighlightChanged: (v) => setState(() => _down = v),
          child: widget.child,
        ),
      ),
    );
  }
}

/// Counts up to [value] when it first appears or changes.
class AnimatedCount extends StatelessWidget {
  const AnimatedCount(this.value, {super.key, this.style});
  final int value;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    if (!Motion.enabled(context)) return Text('$value', style: style);
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: value.toDouble()),
      duration: Motion.slow,
      curve: Motion.emphasized,
      builder: (_, v, _) => Text('${v.round()}', style: style),
    );
  }
}

/// Shimmering placeholder shown while local data loads.
class Shimmer extends StatefulWidget {
  const Shimmer({super.key, required this.child});
  final Widget child;

  @override
  State<Shimmer> createState() => _ShimmerState();
}

class _ShimmerState extends State<Shimmer> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1300));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (Motion.enabled(context)) {
      _c.repeat();
    } else {
      _c.stop();
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final base = cs.surfaceContainerHighest;
    final hi = Color.lerp(base, cs.surface, 0.7)!;
    return AnimatedBuilder(
      animation: _c,
      child: widget.child,
      builder: (_, child) => ShaderMask(
        blendMode: BlendMode.srcATop,
        shaderCallback: (r) => LinearGradient(
          begin: Alignment(-1.5 + 3 * _c.value, -0.3),
          end: Alignment(-0.5 + 3 * _c.value, 0.3),
          colors: [base, hi, base],
        ).createShader(r),
        child: child,
      ),
    );
  }
}

class SkeletonBox extends StatelessWidget {
  const SkeletonBox({super.key, this.width, this.height = 14, this.radius = 8});
  final double? width;
  final double height;
  final double radius;

  @override
  Widget build(BuildContext context) => Container(
        width: width,
        height: height,
        decoration: BoxDecoration(color: Theme.of(context).colorScheme.surfaceContainerHighest, borderRadius: BorderRadius.circular(radius)),
      );
}

/// Placeholder list of patient-style cards.
class SkeletonList extends StatelessWidget {
  const SkeletonList({super.key, this.count = 6, this.padding = const EdgeInsets.fromLTRB(16, 12, 16, 16)});
  final int count;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) => Shimmer(
        child: ListView.separated(
          physics: const NeverScrollableScrollPhysics(),
          padding: padding,
          itemCount: count,
          separatorBuilder: (_, _) => const SizedBox(height: 12),
          itemBuilder: (_, i) => Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(border: Border.all(color: Theme.of(context).colorScheme.surfaceContainerHighest), borderRadius: BorderRadius.circular(22)),
            child: Row(children: [
              const SkeletonBox(width: 46, height: 46, radius: 23),
              const SizedBox(width: 14),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  SkeletonBox(width: 120 + (i % 3) * 30.0, height: 14),
                  const SizedBox(height: 8),
                  SkeletonBox(width: 80 + (i % 2) * 50.0, height: 11),
                ]),
              ),
            ]),
          ),
        ),
      );
}

/// IndexedStack (every tab keeps its state; hidden tabs are offstage) that fades and lifts the tab being shown.
class FadeIndexedStack extends StatelessWidget {
  const FadeIndexedStack({super.key, required this.index, required this.children});
  final int index;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Stack(fit: StackFit.expand, children: [
        for (var i = 0; i < children.length; i++) _TabFade(active: i == index, child: children[i]),
      ]);
}

class _TabFade extends StatefulWidget {
  const _TabFade({required this.active, required this.child});
  final bool active;
  final Widget child;

  @override
  State<_TabFade> createState() => _TabFadeState();
}

class _TabFadeState extends State<_TabFade> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: Motion.medium, value: widget.active ? 1 : 0);
  late final Animation<double> _t = CurvedAnimation(parent: _c, curve: Motion.emphasized);

  @override
  void didUpdateWidget(_TabFade old) {
    super.didUpdateWidget(old);
    if (widget.active && !old.active) {
      if (Motion.enabled(context)) {
        _c.forward(from: 0);
      } else {
        _c.value = 1;
      }
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Offstage(
        offstage: !widget.active,
        child: TickerMode(
          enabled: widget.active,
          child: FadeTransition(
            opacity: _t,
            child: SlideTransition(position: Tween(begin: const Offset(0, 0.02), end: Offset.zero).animate(_t), child: widget.child),
          ),
        ),
      );
}
