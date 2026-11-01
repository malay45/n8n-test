=== Launch Reveal ===
Stable tag: 1.0.0
License: GPL-2.0-or-later

Show a full-screen launch image when the site is opened with a query parameter (default ?launch=1). Click, tap, Enter, Space or Esc reveals the website with an animation.

== Usage ==
1. Upload launch-reveal.zip in Plugins → Add New → Upload, then activate it.
2. Open https://your-site.com/?launch=1 to see the launch screen. Click it to reveal the site.
3. To change settings, go to Settings → Launch Reveal. You can set:
   * The query parameter name and value. Leave the value empty to match any value.
   * The image. The Pramukh Academy Website 2.0 poster is bundled as the default, and you can pick another from the Media Library.
   * The fit (contain or cover) and the background colour.
   * The animation: Curtain split (default), Iris, or Zoom & blur.
   * The hint text, a confetti burst, and removing the parameter from the URL after launch.

== Notes ==
* Lightweight: no jQuery, no GSAP and no external files. The CSS and JS (about 4 KB) are printed inline only when the parameter matches. Normal visits load nothing.
* Uses the native Web Animations API. When a user has "reduced motion" turned on, the overlay just fades out.
* Launch responses send no-cache headers and DONOTCACHEPAGE. If a CDN is set to ignore query strings (for example Cloudflare "Ignore query string"), add a bypass rule for the parameter.
* After launch, JS fires the `launchreveal:done` event on document.
