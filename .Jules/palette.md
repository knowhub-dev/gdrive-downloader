## 2026-02-22 - [Terminal Validation Pattern]
**Learning:** Using existing UI components (like a terminal log) for validation feedback maintains immersion better than browser alerts.
**Action:** When working on themed UIs, adapt standard feedback mechanisms (alerts, toasts) to match the theme (terminal logs, matrix text) while ensuring accessibility via ARIA roles.

## 2026-02-24 - [Themed Focus Visibility]
**Learning:** Standard browser focus rings often clash with or are invisible in dark/themed interfaces (like Matrix style). Using `box-shadow` to create a "glow" effect provides a high-contrast, theme-appropriate focus indicator that is superior to `outline: none` alone.
**Action:** Replace default outlines with theme-consistent `box-shadow` or `border` styles for focus states to ensure keyboard accessibility doesn't break immersion.

## 2026-04-06 - [Synchronous Loading Feedback]
**Learning:** Even synchronous form submissions (like standard POSTs) benefit from immediate visual feedback. Disabling the submit button and changing its text to a loading state prevents duplicate clicks and improves perceived performance, which is a key UX pattern.
**Action:** Add visual loading states (e.g., button disable + text change) to standard forms via JavaScript to provide immediate feedback before page navigation occurs.

## 2026-05-18 - [Semantic HTML5 Input Types]
**Learning:** Using semantic HTML5 input types (like `type="url"`) for inputs enhances mobile UX by automatically triggering context-aware keyboards, which makes data entry smoother.
**Action:** Always prefer semantic input types for specific data types, but ensure that any CSS styles targeting general types like `input[type="text"]` are updated to include the new types (e.g., `input[type="url"]`) to prevent style regressions.
