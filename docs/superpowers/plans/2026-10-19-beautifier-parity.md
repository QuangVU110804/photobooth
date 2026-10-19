# Face Beautifier parity implementation plan

> **For agentic workers:** Use superpowers:executing-plans inline; one independent
> review after integration. User requested a working trial of the approved design.

**Goal:** All 21 reference controls affect both preview and the saved photo.
**Architecture:** Local upstream GPU modules, shared existing face detector and a
bounded processed-image handoff to the existing PHP output pipeline.
**Tech stack:** Vanilla JS, WebGL2, MediaPipe Worker, PHP/GD, existing Chrome/CDP tests.
**Spec:** ../specs/2026-10-19-beautifier-parity-design.md

## Constraints and review focus

No new npm/model dependencies, no persistent intermediate images, preserve dirty
workspace and unrelated logic. Start Off. Preserve original photo policy.
Review stale inference/settings, multi-face warp limits, sticker mapping, original
dimensions/EXIF, failed GPU initialization and cleanup, invalid image uploads.

## Tasks

- [x] Controls/session: failing behavior tests, upstream settings and validation,
  immutable confirmed state, preset and reset semantics; run Node checks.
- [x] Renderer: port pinned source, add four-face uniforms, bounds, cleanup and
  forward anchor mapping; verify real shader pixels and geometry in Chrome.
- [x] Interface: 21 translated sliders, palettes/presets, comparison/split/mirror/
  mask; retain existing effect controls and touch/keyboard access.
- [x] Capture/API: fresh still processing, one transient JPEG, validate original
  dimensions/type/size before writes, apply existing output stages once; PHP tests.
- [x] Browser checks, existing regressions, independent review and focused fixes.

## Execution record

Ruling: implement the requested trial inline in the shared checkout, preserving
all existing work and using a temporary baseline snapshot. Do not create commits,
worktrees or move user changes for this trial. No external publication.
Research: all eight required upstream rendering modules downloaded into the OS
temporary folder from the pinned commit; full source inspected before adaptation.

Ruling: cap full-resolution beauty export at 16,000,000 pixels and the GPU texture
limit, rejecting larger captures before buffer allocation. Do not silently reduce
saved dimensions. This bounds five-pass GPU memory; DSLR users with larger captures
must lower their camera resolution or choose Off. The limit is documented in README.

Controls/session: complete. All 21 original ranges, six presets, ten swatches,
validation and immutable confirmed settings verified by Node behavior tests.
Renderer/UI: complete. Local pinned shaders, half/quarter-resolution passes,
four-face uniforms (64 active warps), reusable geometry/mask buffers, One Euro
tracking, and GPU teardown/recreation. 960px / 30fps preview and one detector.
Beauty JavaScript totals 59,191 bytes before transfer compression; no new model or
npm dependency. Desktop preview remains visible while scrolling the settings.
Capture/API: complete. One in-memory JPEG, strict JPEG/base64/size/upright-dimension
validation, original retention, normalized EXIF metadata, existing output pipeline.
PHP post_max_size is 40M on this installation; other installations must accommodate
the documented bounded request.

Independent review: no critical finding; two important findings addressed:
- Still-warped sticker anchors are now computed before asynchronous JPEG encoding.
  Regression dispatches visibilitychange during a delayed toBlob callback. Reverting
  the fix made the assertion fail; restoring it passed the complete beauty suite.
- Corrupted new Vietnamese template labels and oversized-image error corrected as
  UTF-8. Browser text assertions, source checks and rendered screenshots confirm it.

Final verification, 2026-10-19:
- `node scripts/test-filter-stage-browser.mjs --beauty-only`: 51 checks passed,
  real MediaPipe/WebGL2/PHP path, all 21 controls change pixels, both palettes,
  four-face warp capacity, export dimensions/limits, capture race, GPU loss/reuse,
  responsive layouts at 375px and 1440px. No browser/PHP runtime errors.
- `php scripts/test-filter-effects.php`: 180 checks, zero failures.
- Beauty controls, forward geometry, upload validation and JavaScript/module syntax
  passed. `npm test` also passed PHP pipeline 5/5, resize 13, API logic 130, then
  stopped at the pre-existing missing stethoscope.png catalog entry.
- Remaining PHP receipt 69, deployment 7, frame previews 40, scroll, Node capture
  session 14, gallery polling 22 and kiosk zoom 10 passed independently.
- Receipt browser suite: 62 checks passed.
- Existing filter browser suite reached PNG-sticker export and failed because
  catalog validation rejects the missing stethoscope.png. Existing Node sticker
  suite still fails its 1500px expectation against the supplied 1254px mask.png.
  Catalog/assets were left unchanged under the user's unrelated-change constraint.

Trial remains in the shared checkout for local use. No commits, merge, push,
publication or user-change cleanup performed. Detailed test logs/screenshots are
in ignored reports/; temporary reference/baseline files remain outside the project.

## Release review, 2026-10-19

The user subsequently authorized committing/pushing the necessary pending source,
reusing the existing feature branch, verifying the commit and merging to master
only after checks pass. This supersedes the trial's no-commit/no-push scope.

An independent pre-merge review covered the pending camera, gallery, sticker,
beauty, configuration and output changes. Release fixes:
- User approved temporarily removing the missing stethoscope asset from the
  shipped catalog. Crown, hat and mask remain; the supplied 1254px mask is unchanged.
- Normalize oriented DSLR pixels before applying browser-upright face placements
  when GPU beauty is Off. All eight EXIF orientations are covered for both legacy
  polygons and PNGs; retained JPEG bytes remain unchanged. GPU export stays upright.
- Preserve kiosk navigation while a gallery deletion is in flight after cancel.
  The browser regression failed before the guard and passes with it.
- Deployment troubleshooting docs use generic examples rather than private host,
  computer or capture identifiers. The actual tunnel/DNS issue remains deferred.

Verification: full npm test passes, including 260 effect/API checks, 78 PHP PNG
checks, 64 JS PNG checks and all existing regression groups. Browser checks include
51 GPU beauty checks, 123 capture/gallery/PNG checks and the receipt HTTP suite.
No npm dependency or model was added. Runtime current_active_frame.png, data,
private configuration and generated reports are excluded from the source commit.
