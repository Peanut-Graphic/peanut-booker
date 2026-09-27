# Accessibility (a11y) Guidelines - Peanut Booker

This document outlines accessibility standards, testing practices, and guidelines for the Peanut Booker platform. We aim to meet **WCAG 2.1 Level AA** compliance for all features.

## Table of Contents

1. [Overview](#overview)
2. [Standards & Guidelines](#standards--guidelines)
3. [CI/CD Pipeline](#cicd-pipeline)
4. [Component Accessibility](#component-accessibility)
5. [Testing Strategy](#testing-strategy)
6. [Common Issues & Fixes](#common-issues--fixes)
7. [Resources](#resources)

---

## Overview

The Peanut Booker platform is a performer booking system with features including:
- **Calendar/Scheduling**: React-big-calendar for booking management
- **Drag-and-Drop**: @dnd-kit for reordering and organizing bookings
- **Analytics**: Chart.js visualizations with text alternatives
- **Forms**: Complex booking forms with validation
- **Tables**: Performer/customer/booking data grids
- **Real-time Notifications**: Toast and alert components

All features must be accessible via:
- Keyboard navigation (Tab, Enter, Arrow keys, Escape)
- Screen readers (NVDA, JAWS, VoiceOver, TalkBack)
- Voice control
- High contrast and zoom modes

---

## Standards & Guidelines

### WCAG 2.1 Level AA

We follow the Web Content Accessibility Guidelines (WCAG) 2.1 at Level AA, which includes:

#### Perceivable (Information must be presentable)
- **1.4.3 Contrast (Minimum)**: Text should have at least 4.5:1 contrast ratio
- **1.4.11 Non-text Contrast**: UI components should have 3:1 contrast minimum
- **1.1.1 Non-text Content**: All images must have appropriate alt text

#### Operable (Interface must be navigable)
- **2.1.1 Keyboard**: All functionality available via keyboard
- **2.1.2 No Keyboard Trap**: Focus must not get permanently stuck
- **2.4.3 Focus Order**: Logical tab order that matches visual flow
- **2.5.5 Target Size**: Clickable elements at least 44x44px (or visible focus)

#### Understandable (Content must be clear)
- **3.2.4 Consistent Identification**: Components behave consistently
- **3.3.1 Error Identification**: Form errors clearly marked and explained
- **3.3.4 Error Prevention**: Critical actions require confirmation

#### Robust (Works with assistive technologies)
- **4.1.2 Name, Role, Value**: All components have proper semantics
- **4.1.3 Status Messages**: Live regions announce changes

### Booking Platform Specific

For Peanut Booker specifically:

1. **Calendar Events**: Must be keyboard selectable and editable
2. **Drag-and-Drop**: Must have keyboard alternatives (cut/copy/paste or arrow keys)
3. **Status Indicators**: Never use color alone; include text labels or icons
4. **Charts/Analytics**: Include data table alternatives or aria-describedby
5. **Date Pickers**: Support multiple input formats and keyboard navigation
6. **Time Selection**: Clear indication of AM/PM, timezone, and format

---

## CI/CD Pipeline

### GitHub Actions Workflow

The accessibility pipeline runs on every pull request to `main` branch.

**Location**: `.github/workflows/accessibility.yml`

**Steps**:
1. Node.js environment setup (v22.23.2 with npm 10.9.8)
2. Install dependencies via npm ci
3. Run ESLint with jsx-a11y rules
4. Execute vitest a11y test suite
5. Report violations and fail on critical errors

### Local Testing

#### Run All A11y Checks
```bash
./scripts/a11y-check.sh
```

#### Run Only ESLint
```bash
./scripts/a11y-check.sh --eslint
```

#### Run Only Unit Tests
```bash
./scripts/a11y-check.sh --unit
```

#### Run with Coverage
```bash
./scripts/a11y-check.sh --coverage
```

#### Manual Test Commands
```bash
# ESLint a11y rules
cd frontend && npx eslint --ext .js,.jsx,.ts,.tsx src/ --rule 'jsx-a11y/alt-text: warn'

# Vitest a11y tests
npm run test:a11y

# Vitest with coverage
npm run test:a11y -- --coverage
```

---

## Component Accessibility

### Button

**WCAG Requirements**:
- Must have accessible name (text, aria-label, or aria-labelledby)
- Must be keyboard accessible (focusable with Enter/Space)
- Must have visible focus indicator
- Disabled state must be programmatic

**Implementation**:
```tsx
<Button>Click me</Button>

// With icon only, use aria-label
<Button aria-label="Close dialog" variant="ghost">
  <X />
</Button>

// With disabled state
<Button disabled aria-disabled="true">Save</Button>
```

**Testing**:
```tsx
it('button should be keyboard accessible', async () => {
  const user = userEvent.setup();
  render(<Button>Submit</Button>);
  const button = screen.getByRole('button');

  await user.tab();
  expect(button).toHaveFocus();
});
```

### Form Input

**WCAG Requirements**:
- Every input must have associated `<label>` with `htmlFor` attribute
- Error messages must be linked via `aria-describedby`
- Input type must match expected data (email, number, date, etc.)
- Required fields must be marked with `aria-required="true"`

**Implementation**:
```tsx
<Input
  label="Email Address"
  type="email"
  required
  error={errors.email}
  aria-describedby="email-error"
  id="email"
/>

// With error
<span id="email-error" role="alert">
  Invalid email format
</span>
```

**Testing**:
```tsx
it('input should have associated label', () => {
  render(<Input label="Username" />);
  const input = screen.getByLabelText(/username/i);
  expect(input).toBeInTheDocument();
});
```

### Select / Dropdown

**WCAG Requirements**:
- Must have associated label
- Options must be keyboard navigable (arrow keys)
- Supports screen reader announcements of selected value
- Open/closed state must be announced

**Implementation**:
```tsx
<Select
  label="Booking Status"
  options={[
    { label: 'Pending', value: 'pending' },
    { label: 'Confirmed', value: 'confirmed' },
  ]}
  aria-describedby="status-help"
/>

<span id="status-help">
  Select the current status of the booking
</span>
```

### Table

**WCAG Requirements**:
- First row must be `<thead>` with `<th>` elements
- Row headers must have `scope="row"` attribute
- Complex tables need summary or `aria-describedby`
- Data cells in `<tbody>`

**Implementation**:
```tsx
<table>
  <thead>
    <tr>
      <th scope="col">Performer Name</th>
      <th scope="col">Status</th>
      <th scope="col">Revenue</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Jane Doe</td>
      <td>Active</td>
      <td>$5,000</td>
    </tr>
  </tbody>
</table>
```

### Badge / Status Indicator

**WCAG Requirements**:
- Never rely on color alone for meaning
- Must have text label or icon with aria-label
- Color contrast must meet 3:1 minimum

**Implementation**:
```tsx
// Good - has text label
<Badge variant="success">Confirmed</Badge>

// Good - icon with accessible label
<Badge variant="error" aria-label="Booking cancelled">
  <X />
</Badge>

// Bad - color only, no meaning
<div style={{ background: 'green' }}></div>
```

### Modal / Dialog

**WCAG Requirements**:
- Must have proper `role="dialog"` or semantic `<dialog>`
- Must have accessible name via `aria-labelledby`
- Focus must be trapped inside modal (Tab doesn't escape)
- Escape key closes modal
- Backdrop click closes modal (with confirmation if data loss)

**Implementation**:
```tsx
<Modal
  isOpen={true}
  onClose={handleClose}
  aria-labelledby="modal-title"
>
  <Modal.Header id="modal-title">
    Confirm Booking
  </Modal.Header>
  <Modal.Body>
    Are you sure you want to confirm this booking?
  </Modal.Body>
  <Modal.Footer>
    <Button onClick={handleClose}>Cancel</Button>
    <Button variant="primary" onClick={handleConfirm}>
      Confirm
    </Button>
  </Modal.Footer>
</Modal>
```

### Calendar (react-big-calendar)

**WCAG Requirements**:
- Month/year view must be announced
- Keyboard navigation (arrow keys between dates)
- Selected date must be visually distinct and announced
- Events should be keyboard accessible for editing
- Time zone and booking format must be clear

**Implementation Tips**:
```tsx
<Calendar
  localizer={localizer}
  events={bookings}
  startAccessor="start"
  endAccessor="end"
  onSelectEvent={handleEventSelect}
  onSelectSlot={handleSlotSelect}
  selectable
  // Ensure aria-label on calendar container
  aria-label="Booking Calendar - March 2026"
/>
```

**Keyboard Support**:
- Arrow keys: Navigate between dates
- Enter: Select/edit event
- Escape: Close event details
- Alt+Tab: Move between calendar and other controls

### Drag-and-Drop (@dnd-kit)

**WCAG Requirements**:
- Must have keyboard alternative (not just mouse drag)
- Recommended: Arrow keys to move, Enter to drop
- Or: Cut/Copy/Paste operations
- Announce what was dropped and its new location
- Provide undo capability

**Implementation Pattern**:
```tsx
// With keyboard support
<div
  draggable={true}
  onDragStart={handleDragStart}
  onKeyDown={(e) => {
    if (e.key === 'ArrowDown') moveDown();
    if (e.key === 'Enter') drop();
  }}
  role="button"
  tabIndex={0}
  aria-grabbed={isDragging}
  aria-dropeffect="move"
>
  Draggable Item
</div>

// Announce result
<div role="status" aria-live="polite">
  Booking moved to {dropDate}
</div>
```

### Charts & Analytics

**WCAG Requirements**:
- Chart must have `aria-label` describing the data
- Must provide `aria-describedby` to data table or summary
- Alternative: Include hidden data table with actual values
- Colors must have sufficient contrast
- Legend must be keyboard accessible

**Implementation**:
```tsx
<div aria-label="Revenue by Month Chart">
  <Chart data={chartData} options={chartOptions} />

  {/* Alternative: Hidden data table */}
  <table aria-hidden="true" className="sr-only">
    <thead>
      <tr>
        <th>Month</th>
        <th>Revenue</th>
      </tr>
    </thead>
    <tbody>
      {data.map(row => (
        <tr key={row.month}>
          <td>{row.month}</td>
          <td>${row.revenue}</td>
        </tr>
      ))}
    </tbody>
  </table>
</div>

// CSS for screen-reader-only content
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border-width: 0;
}
```

### Date/Time Pickers

**WCAG Requirements**:
- Format hints required (e.g., MM/DD/YYYY)
- Supports multiple input methods (keyboard, picker UI)
- Tab through date parts or use arrow keys
- Clear error messaging for invalid dates
- Timezone indication required

**Implementation**:
```tsx
<Input
  label="Event Date"
  type="date"
  placeholder="MM/DD/YYYY"
  aria-describedby="date-format-help"
  required
/>

<span id="date-format-help">
  Enter date in MM/DD/YYYY format
</span>
```

---

## Testing Strategy

### Automated Testing (jest-axe)

We use jest-axe for automated accessibility testing in the vitest suite.

**Test File**: `frontend/src/test/accessibility/components.a11y.test.tsx`

**Pattern**:
```tsx
import { axe, toHaveNoViolations } from 'jest-axe';

expect.extend(toHaveNoViolations);

describe('Component Accessibility', () => {
  it('should not have automated a11y violations', async () => {
    const { container } = render(<MyComponent />);
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});
```

**Limitations**:
- Jest-axe catches ~30-40% of accessibility issues
- Automated testing cannot detect:
  - Color contrast (requires visual inspection)
  - Screen reader announcements (requires manual testing)
  - Keyboard navigation issues (requires manual testing)
  - Focus management problems

### Manual Testing

**Required Manual Tests**:

1. **Keyboard Navigation**
   - Tab through entire page
   - Shift+Tab to go backwards
   - Enter/Space on buttons
   - Arrow keys in selects/calendars
   - Escape to close modals

2. **Screen Reader Testing**
   - Use NVDA (Windows), VoiceOver (Mac), TalkBack (Android)
   - Verify page structure and headings
   - Check form labels and error messages
   - Test table navigation

3. **Visual Inspection**
   - Zoom to 200% (page should remain usable)
   - Check color contrast (4.5:1 for normal text, 3:1 for large text)
   - Verify focus indicators visible
   - Test high contrast mode

4. **Mobile Testing**
   - Voice control (Android TalkBack, iOS VoiceOver)
   - Gesture navigation
   - Text sizing and zoom

### Accessibility Audit Tools

**Browser Extensions**:
- **axe DevTools**: https://www.deque.com/axe/devtools/
- **WAVE**: https://wave.webaim.org/extension/
- **Lighthouse**: Built into Chrome DevTools (Accessibility tab)

**Command Line**:
```bash
# ESLint jsx-a11y plugin
npm run lint -- --rule 'jsx-a11y/*: warn'

# Pa11y CLI for full page audits
npm install -g pa11y-ci
pa11y-ci http://localhost:3000
```

---

## Common Issues & Fixes

### Issue: Missing Form Labels

**Problem**: Input without associated label breaks form accessibility.

**Bad**:
```tsx
<input type="text" placeholder="Email" />
```

**Good**:
```tsx
<label htmlFor="email">Email Address</label>
<input id="email" type="email" />
```

### Issue: Image Without Alt Text

**Problem**: Screen reader users can't understand image content.

**Bad**:
```tsx
<img src="booking-chart.png" />
```

**Good**:
```tsx
<img
  src="booking-chart.png"
  alt="Monthly bookings increased from 20 to 35 between Jan and Mar"
/>
```

### Issue: Color-Only Status Indicator

**Problem**: Colorblind users can't distinguish status.

**Bad**:
```tsx
<div style={{ background: 'red' }} />  {/* Only shows color */}
```

**Good**:
```tsx
<Badge variant="error">Cancelled</Badge>  {/* Has text label */}
```

### Issue: Non-Keyboard-Accessible Button

**Problem**: Button implemented as `<div>` with click handler.

**Bad**:
```tsx
<div onClick={handleClick}>
  Delete Booking
</div>
```

**Good**:
```tsx
<button onClick={handleClick}>
  Delete Booking
</button>
```

### Issue: Focus Indicator Removed

**Problem**: CSS removes default focus outline.

**Bad**:
```css
button {
  outline: none;  /* Removes focus indicator! */
}
```

**Good**:
```css
button {
  outline: 2px solid #0066cc;
  outline-offset: 2px;
}

/* Or use browser default with visible fallback */
button:focus-visible {
  outline: 2px solid currentColor;
  outline-offset: 2px;
}
```

### Issue: Modal Focus Not Trapped

**Problem**: Tab key escapes modal to page behind.

**Solution**: Use `@headlessui/react` Dialog or implement focus trap:
```tsx
import { useEffect } from 'react';

function Modal({ isOpen, onClose }) {
  useEffect(() => {
    if (!isOpen) return;

    const handleKeyDown = (e) => {
      if (e.key === 'Escape') onClose();
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  return (
    <div role="dialog" aria-modal="true">
      {/* Modal content */}
    </div>
  );
}
```

### Issue: Drag-and-Drop Not Keyboard Accessible

**Problem**: Only mouse users can drag items.

**Solution**: Add keyboard alternative:
```tsx
function DraggableItem({ item }) {
  const [selected, setSelected] = useState(false);

  const handleKeyDown = (e) => {
    if (e.key === 'Enter') setSelected(true);
    if (e.key === 'ArrowDown' && selected) moveDown();
    if (e.key === 'ArrowUp' && selected) moveUp();
  };

  return (
    <div
      role="button"
      tabIndex={0}
      aria-grabbed={selected}
      onKeyDown={handleKeyDown}
    >
      {item.name}
    </div>
  );
}
```

---

## Resources

### Documentation
- [WCAG 2.1 Guidelines](https://www.w3.org/WAI/WCAG21/quickref/)
- [Web AIM Color Contrast Checker](https://webaim.org/resources/contrastchecker/)
- [MDN ARIA Documentation](https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA)
- [React Accessibility Best Practices](https://reactjs.org/docs/accessibility.html)

### Testing Tools
- [jest-axe Documentation](https://github.com/nickcolley/jest-axe)
- [eslint-plugin-jsx-a11y](https://github.com/jsx-eslint/eslint-plugin-jsx-a11y)
- [axe DevTools](https://www.deque.com/axe/devtools/)
- [WebAIM Screen Reader Testing](https://webaim.org/articles/screenreader_testing/)

### Libraries We Use
- [@dnd-kit/core](https://docs.dndkit.com/) - Accessible drag-and-drop
- [react-big-calendar](https://jquense.github.io/react-big-calendar/) - Accessible calendar
- [@headlessui/react](https://headlessui.com/) - Unstyled, accessible components
- [radix-ui](https://www.radix-ui.com/) - Primitive components with a11y built-in

### Team Training
- Quarterly accessibility reviews
- Screen reader testing sessions (NVDA/VoiceOver)
- Color contrast audits
- Keyboard navigation walkthroughs

---

## Checklist for New Components

Before committing new components:

- [ ] Component has proper semantic HTML
- [ ] All interactive elements are keyboard accessible
- [ ] Form inputs have associated labels
- [ ] Error messages are clear and linked to fields
- [ ] Focus indicators are visible
- [ ] Color contrast meets WCAG AA (4.5:1 normal, 3:1 large)
- [ ] ARIA attributes used correctly (not over-used)
- [ ] Component passes jest-axe tests
- [ ] Tested with screen reader (NVDA or VoiceOver)
- [ ] Tested with keyboard only (no mouse)
- [ ] Works at 200% zoom
- [ ] Works in high contrast mode
- [ ] Documentation includes a11y notes

---

## Continuous Improvement

We track accessibility metrics and improvements:

- **Monthly Reviews**: Audit new features for a11y compliance
- **User Feedback**: Accessibility issues reported by users are P1
- **Dependency Updates**: Keep @dnd-kit, react-big-calendar, and jest-axe current
- **Team Training**: Quarterly a11y workshops and testing sessions

For questions or issues, reach out to the team on Slack or create an issue in the tracker.

**Last Updated**: 2026-03-28
**Version**: 1.0.0
**Maintainer**: Peanut Booker Development Team
