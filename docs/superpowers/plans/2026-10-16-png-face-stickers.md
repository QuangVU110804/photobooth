# Configurable PNG Face Stickers Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Add configurable PNG face stickers to preview and saved JPG without changing unrelated capture behavior.

**Architecture:** Extend the existing faceEffects element list with a tagged PNG element. A shared JSON catalog feeds a small JS geometry/cache module and a bounded GD renderer. Preserve legacy polygon routing, camera flow, filters and frame ordering.

**Tech Stack:** Existing MediaPipe Worker, Canvas 2D, PHP 8/GD, Node built-in test utilities and Chrome/CDP.

**Spec:** ../specs/2026-10-16-png-face-stickers-design.md

## Global Constraints

- Only the spec's four existing runtime files; new focused modules/catalog/assets/tests/docs.
- Preserve all pre-existing edits; no broad formatting, dependency installation, vendor/config changes or unrelated fixes.
- 32 effects, 4 parts/effect, 4 faces, 16 PNG elements/image, existing 32 element/64 KiB payload bounds.
- PNG <=1536x1536 and 2 MiB; intermediate GD surfaces <=4 million pixels; client cache <=8 million pixels and two effects.
- Keep one Worker/in-flight preview, 125ms scheduling, 480px preview inference and 960px still inference/overlay caps.
- One selected effect; keep legacy none/glasses/blush unchanged. Catalog schema version 1; PNG-only with named anchor presets.

## Review Focus

- Delayed asset/Worker responses after changing effect: discard stale geometry and prevent shoot while assets load (Task 3 browser tests).
- Asymmetric origin and signed rotation: Canvas/GD agree on center and angle (Tasks 1/2 geometry and pixel fixtures).
- Malformed late payload element: reject before output files change (Task 2 endpoint snapshots).
- Catalog or asset failure: none/legacy capture still usable and invalid PNG choice disabled (Task 3 browser fault injection).
- Multiple faces and repeated sessions: cache/inference remain bounded and choices reset (Tasks 1/3/4).

## Task 1: Catalog, geometry and asset lifecycle

**Files:** new resources/js/face-stickers.js, resources/face-effects/catalog.json,
resources/face-effects/stickers/crown.png, scripts/test-face-stickers.cjs.

**Interfaces:** HmuFaceStickers.validateCatalog(raw) -> normalized effects array;
indicesFor(effect) -> landmark indices (legacy four first); placements(faces,effect,w,h) ->
tagged elements {type:'sticker',effect,part,center:[x,y],width,angle};
draw(context,effect,elements,images,w,h); AssetCache.prepare(effect) -> Map filename/ImageBitmap;
AssetCache.clear(); browser loadPng(file) -> bounded ImageBitmap.
Catalog parts: file, anchor, width, optional origin [0..1]^2, offset [-2..2]^2, opacity [0..1].

- [x] Write assertions for schema/bounds, independent geometry expected values, signed rotation, origins, 0/4 faces, deduplicated asset loading, eviction and cancellation.
- [x] Run node scripts/test-face-stickers.cjs; expect missing feature assertion.
- [x] Implement module, catalog and original crown PNG. Width is relative to inter-eye distance, exported width relative to raw image width; preserve texture aspect ratio.
- [x] Run JS tests; expect all pass.

## Task 2: PHP validation and bounded alpha compositor

**Files:** new lib/face-stickers.php, scripts/test-face-stickers.php;
modify lib/face-effects.php and test-filter-effects.php fixture only.

**Interfaces:** stickerCatalog(?directory) -> normalized map;
parseFaceSticker(stdClass) -> validated tagged array; applyFaceStickers(GdImage,array) -> void.
Catalog default directory is resources/face-effects; validation returns same defaults as JS.
PNG metadata from getimagesize before decode; resolve paths via realpath under stickers directory.

- [x] Write independent alpha, clockwise/counterclockwise, clipping, size, origin geometry-consumption and endpoint fixtures; malformed schema/path/payload must fail before writes.
- [x] Run PHP test; expect missing feature assertion.
- [x] Implement catalog resolver/parser and GD renderer. Resize/rotate sticker-sized surfaces, honor opacity, reject budget overflow before allocation, cache decoded source per request and free intermediates.
- [x] Extend existing parser/renderer with tagged dispatch; leave polygon validation and rendering intact; ensure all payload elements validate first.
- [x] Copy catalog/assets into existing isolated backend fixture; run PHP sticker and legacy filter tests, expect zero failures.

## Task 3: Integrate preview, selection and raw export

**Files:** resources/js/filter-stage.js, resources/js/face-effects-worker.js, index.php;
scripts/test-filter-stage-browser.mjs; scripts/filter-test-router.php only if required for fault injection.

**Consumes:** Task 1 JS module and Task 2 tagged payload through unchanged core/API route.
Worker request optional indices array (4..32 distinct integers in [0,477]); default legacy four.
Each pending preview captures effect selection revision; ignore stale response on effect change.

- [x] Add browser assertions: PNG option exists, alpha preview, real still-image exported PNG, filter/frame/flip compatibility, delayed load cancellation, missing asset, reset, one in-flight inference.
- [x] Run browser test; expect missing PNG option failure.
- [x] Wire lazy catalog load, guarded asset selection, legacy/PNG draw dispatch, indices request and raw still placements. Keep legacy function bodies/route unchanged wherever possible.
- [x] Add bounded Worker index support and script version bump. Keep default output unchanged.
- [x] Run full browser tests; expect no JS errors and all old/new assertions pass.

## Task 4: Performance, documentation and whole-project verification

**Files:** resources/face-effects/README.md, scripts/run-project-tests.cjs;
tests above for benchmarks; update spec checklist and this plan.

- [x] Add optional same-process benchmark mode with warmed median/p95 timings and peak memory at 1280x720,1800x1200,3840x2160, 1/4 faces; compare legacy renderer against captured pre-change source.
- [x] Register PHP/JS sticker tests; write copyable JSON example and calibration/limits guide.
- [x] Run npm.cmd test and npm.cmd run test:browser; both exit 0.
- [x] Run benchmarks, inspect exported screenshot/pixel evidence and compare baseline hashes: only allowed files differ.
- [x] Run git diff --check, review whole change with one independent reviewer, address material findings with reproducing tests; rerun relevant/full suite after fixes.
- [x] Report exact test/benchmark results and any real-hardware limitations. Leave implementation reviewable without committing pre-existing user edits or pushing.

## Execution record

User explicitly approved the spec and requested implementation in this session. Execute natively without another permission loop. Existing dirty files are the required integration baseline; keep this working directory and use a feature branch, with SHA256 hashes to verify unrelated files remain unchanged. Reports/ contains ignored progress and test artifacts. No per-task commits of mixed files; preserve user's prior changes unstaged.
