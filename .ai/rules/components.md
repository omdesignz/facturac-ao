---
paths:
  - 'resources/js/components/**'
---

# Components

## Template transitions require exactly one vnode child
Keep comments outside Headless UI TransitionRoot/TransitionChild when as=template. Vue development builds preserve comment vnodes; a comment beside the actual child makes Headless UI reject ref forwarding and crash the page when the transition becomes visible. WorkSessionTimer regression is covered by tests/work-session-ui.test.mjs at the expiry-warning threshold.
