# Action-Driven Spotlight Tour Implementation Plan

**Goal:** Add a Vietnamese “Hướng dẫn” button that starts an optional tour using real Photobooth controls. Never auto-start or remember completion.

**Architecture:** A dedicated controller and stylesheet use a pinned, locally served Driver.js build. The controller observes existing UI state and real clicks without replacing camera/capture handlers. It removes the overlay during capture and resumes at the available result actions. Preserve pre-existing working-copy edits.

**Tech stack:** PHP templates, browser JavaScript, Driver.js, existing PHP/GD + Chrome CDP integration harness.

**Spec:** User-approved direction in this conversation: manual start, action-driven steps, no persistence, no unrelated logic changes, regression testing required.

## Constraints and review focus

- No auto-start, localStorage/sessionStorage/cookie writes, or simulated user choices in the guide.
- A click is insufficient when frame synchronization is still pending or fails.
- Missing color/QR controls and denied camera access must not trap the user.
- Close/Escape, returning to frames, capture failure, and page exit remove overlays/listeners and restore usable focus.
- No overlay during countdown or processing; only resume after the actual result is visible.
- Support touch, keyboard and reduced motion; preserve original capture behavior.
- Work in the requested existing checkout: it contains relevant uncommitted UI changes. No automatic commits or edits to unrelated files.

## Tasks

- [x] Add browser cases to the existing isolated PHP/GD + fake-camera harness. Observe a failure for the missing guide button before implementation.
- [x] Pin Driver.js and add the button, dedicated controller/style, and conditional assets for the modern UI. Follow real actions: frame → camera → open colors → choose color (including original) → shoot → QR. End the spotlight as soon as the real QR modal opens, keeping the code unobstructed. Skip unavailable optional steps.
- [x] Verify manual start/restart/reload, real clicks and touch, async frame success/failure, permission denial/recovery, overlay suspension and result resumption, unavailable controls, Escape/close focus and capture regression.
- [x] Run npm test and the existing browser regressions, inspect screenshots and diff, then review the scoped changes.

## Execution record

- Plan/design already authorized by the user’s implementation request. Execute inline in this working copy; do not create another approval step.
- Initial test failed for the missing guide button. Additional real-browser failures exposed premature close cleanup, keyboard shortcut overlap and camera-dialog popover placement; each received a failing case and a fix.
- Independent read-only review found two Important issues: keyboard activation of the guide launcher and popovers outside the accessible camera dialog. Both are fixed and covered by the 55-check guide run. No deferred minor findings.
- Existing unrelated working-copy edits are preserved. No camera, image-processing, gallery or receipt handlers were replaced.
- Final verification: npm test exit 0; npm run test:browser exit 0 (55 guide, 53 GPU/beauty, 123 capture/PHP, 62 receipt/HTTP checks). PHP lint and scoped diff whitespace checks also passed. Screenshot inspection completed; physical hardware and assistive-technology runs remain outside the automated checks.
