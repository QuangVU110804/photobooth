# Face Beautifier parity: approved trial

User approved the shared GPU direction and requested implementation of a trial.
Reference: CTBot000/face-beautifier, commit ca5ad40b58e2d0133de6b22282b29000d3667046.

## Scope

Expose all 21 upstream controls, their original ranges and six presets, six lip
swatches and four blush swatches. Keep the HMU visual style, use four collapsible
groups, and provide comparison, split view, mirror and mask visualization.
Start each visitor with Off, consistent with the previously agreed booth default.
Reset uses the upstream slider defaults; selecting Off restores identity.

Reuse upstream five-pass WebGL2 rendering, mask, face geometry, warp and filtering
modules. Adapt to the existing local MediaPipe Worker and up to four faces; no
second detector, remote runtime, npm dependency, video recorder or photo library.
Support smooth tracking, frozen capture settings, and post-warp sticker anchors.

GPU preview uses bounded input at <=960px longest edge, <=30fps, one inference
in flight at approximately 8Hz. Export processes the actual still capture with
fresh detection, hides comparison/mask tools, and sends one bounded JPEG in the
existing effects request. PHP validates it against the raw capture dimensions,
then follows the existing sticker/filter/frame/thumbnail/result pipeline. No new
persistent intermediate image. Original-photo retention policy remains effective.
Respect EXIF orientation; normalized processed pixels must not receive a stale
orientation tag when metadata preservation is enabled.

Preserve existing dirty changes. Limit edits to beauty modules, filter UI/session,
Worker, capture/effects integration and their tests/docs. No changes to gallery,
camera commands, unrelated configuration or sticker catalog/assets.

## Verification

Check every slider against actual rendered pixels or geometry, presets, colors,
identity, multiple faces, control reset/retry, fresh still detection, geometry and
sticker alignment, export upload validation, EXIF, GPU loss/reuse, bounds and UI.
Initial trial baseline: stethoscope.png was missing and mask.png was 1254px while
a sticker test expected 1500px. For release, the user approved omitting the missing
effect; tests preserve the actual supplied PNG sizes and require all suites green.
