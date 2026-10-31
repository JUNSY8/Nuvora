---
name: lpr-integration
description: Design and implement provider-independent license plate recognition for Nuvora.
---
# LPR/ANPR

Never couple parking business logic directly to a specific ANPR vendor.

Use a provider abstraction such as PlateRecognitionProvider.

Normalize recognition results into:
- plate
- confidence
- timestamp
- camera
- lane/direction
- evidence reference
- bounding box when available
- provider metadata

Normalize plates consistently without silently guessing uncertain characters.

Store confidence and support configurable thresholds.

Deduplicate repeated reads from the same vehicle over a configurable time window.

Minimize retention of plate images and restrict access according to configured policy.

LPR recognition is evidence, not an automatic guarantee of identity.
