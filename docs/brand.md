# KioTrad logo

The foreground sage T shares the warm-white K's stem. Both letters rotate as one
group around the figure's bounding-box center: 0 degrees for three seconds,
one second clockwise to 90 degrees, three seconds upright on T, then one second
back. Reduced-motion users see the resting mark.

Use the reusable web component:

```tsx
<Logo size={40} />
<Logo size={64} animated={false} />
<Logo label="" /> // Decorative when the surrounding link already has a name.
```

The default label is KioTrad. Sidebar breakpoints adjust the mark to 30 and 28px.
Both transparent SVG assets have a square viewBox and no fixed dimensions:
`/kiotrad-animated.svg` and `/kiotrad-mark.svg`. They work independently in an img
element with width/height set by the consumer. `/favicon.svg` uses the static
mark with a charcoal background for legibility in light or dark browser chrome.

When embedded inline, CSS variables `--kiotrad-k`, `--kiotrad-t`,
`--kiotrad-duration` and `--kiotrad-play-state` customize the animated SVG. Parent
CSS variables do not cross into an SVG loaded through an img element; edit the
asset defaults or embed inline if custom colors/timing are needed.
