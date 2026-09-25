import { type ReactNode, useState, useRef, useId, useCallback, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { clsx } from 'clsx';
import { HelpCircle } from 'lucide-react';

type TooltipPosition = 'top' | 'bottom' | 'left' | 'right';

interface TooltipProps {
  content: string;
  children: ReactNode;
  position?: TooltipPosition;
  className?: string;
  /** Make the tooltip trigger focusable for keyboard users */
  focusable?: boolean;
}

const arrowStyles: Record<TooltipPosition, string> = {
  top: 'top-full left-1/2 -translate-x-1/2 border-t-slate-800 border-x-transparent border-b-transparent',
  bottom: 'bottom-full left-1/2 -translate-x-1/2 border-b-slate-800 border-x-transparent border-t-transparent',
  left: 'left-full top-1/2 -translate-y-1/2 border-l-slate-800 border-y-transparent border-r-transparent',
  right: 'right-full top-1/2 -translate-y-1/2 border-r-slate-800 border-y-transparent border-l-transparent',
};

const transformStyles: Record<TooltipPosition, string> = {
  top: '-translate-x-1/2 -translate-y-full',
  bottom: '-translate-x-1/2',
  left: '-translate-y-1/2',
  right: '-translate-y-1/2',
};

export default function Tooltip({
  content,
  children,
  position = 'top',
  className,
  focusable = false,
}: TooltipProps) {
  const [isVisible, setIsVisible] = useState(false);
  const [coords, setCoords] = useState({ top: 0, left: 0 });
  const [actualPosition, setActualPosition] = useState(position);
  const tooltipId = useId();
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const triggerRef = useRef<HTMLSpanElement>(null);

  const showTooltip = useCallback(() => {
    clearTimeout(timeoutRef.current);
    timeoutRef.current = setTimeout(() => setIsVisible(true), 200);
  }, []);

  const hideTooltip = useCallback(() => {
    clearTimeout(timeoutRef.current);
    setIsVisible(false);
  }, []);

  // Calculate position when visible
  useEffect(() => {
    if (isVisible && triggerRef.current) {
      const rect = triggerRef.current.getBoundingClientRect();
      const tooltipWidth = 256; // max-w-xs
      const gap = 8;

      // Check if we need to flip position
      let bestPosition = position;
      if (position === 'top' && rect.top < 80) bestPosition = 'bottom';
      else if (position === 'bottom' && rect.bottom + 80 > window.innerHeight) bestPosition = 'top';

      setActualPosition(bestPosition);

      let top = 0;
      let left = 0;

      switch (bestPosition) {
        case 'top':
          top = rect.top - gap;
          left = rect.left + rect.width / 2;
          break;
        case 'bottom':
          top = rect.bottom + gap;
          left = rect.left + rect.width / 2;
          break;
        case 'left':
          top = rect.top + rect.height / 2;
          left = rect.left - gap;
          break;
        case 'right':
          top = rect.top + rect.height / 2;
          left = rect.right + gap;
          break;
      }

      // Keep tooltip within viewport horizontally
      const padding = 8;
      if (left - tooltipWidth / 2 < padding) left = tooltipWidth / 2 + padding;
      if (left + tooltipWidth / 2 > window.innerWidth - padding) {
        left = window.innerWidth - tooltipWidth / 2 - padding;
      }

      setCoords({ top, left });
    }
  }, [isVisible, position]);

  // Handle keyboard events
  const handleKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (e.key === 'Escape' && isVisible) {
      hideTooltip();
      e.preventDefault();
    }
    if ((e.key === 'Enter' || e.key === ' ') && focusable) {
      e.preventDefault();
      setIsVisible(prev => !prev);
    }
  }, [isVisible, hideTooltip, focusable]);

  // Close tooltip when clicking outside
  useEffect(() => {
    if (!isVisible) return;

    const handleClickOutside = (e: MouseEvent) => {
      if (triggerRef.current && !triggerRef.current.contains(e.target as Node)) {
        hideTooltip();
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [isVisible, hideTooltip]);

  // Cleanup timeout on unmount
  useEffect(() => {
    return () => clearTimeout(timeoutRef.current);
  }, []);

  return (
    <span
      ref={triggerRef}
      className={clsx(
        'relative inline-flex',
        focusable && 'rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-1',
        className
      )}
      onMouseEnter={showTooltip}
      onMouseLeave={hideTooltip}
      onFocus={showTooltip}
      onBlur={hideTooltip}
      onKeyDown={handleKeyDown}
      tabIndex={focusable ? 0 : undefined}
      role={focusable ? 'button' : undefined}
      aria-expanded={focusable ? isVisible : undefined}
    >
      <span aria-describedby={isVisible ? tooltipId : undefined}>
        {children}
      </span>
      {isVisible && createPortal(
        <span
          id={tooltipId}
          role="tooltip"
          aria-live="polite"
          className={clsx(
            'fixed z-[99999] px-2.5 py-1.5 text-xs font-medium text-white bg-slate-800 rounded shadow-lg pointer-events-none',
            'whitespace-normal max-w-xs text-center',
            'animate-tooltip-in',
            transformStyles[actualPosition]
          )}
          style={{ top: coords.top, left: coords.left }}
        >
          {content}
          <span
            className={clsx(
              'absolute w-0 h-0 border-4',
              arrowStyles[actualPosition]
            )}
            aria-hidden="true"
          />
        </span>,
        document.body
      )}
    </span>
  );
}

// Convenience component: Help icon with tooltip (keyboard accessible)
interface HelpTooltipProps {
  content: string;
  position?: TooltipPosition;
  size?: 'sm' | 'md';
  className?: string;
  /** Accessible label for screen readers */
  ariaLabel?: string;
}

export function HelpTooltip({
  content,
  position = 'bottom',
  size = 'sm',
  className,
  ariaLabel = 'Help information',
}: HelpTooltipProps) {
  const iconSize = size === 'sm' ? 'w-3.5 h-3.5' : 'w-4 h-4';

  return (
    <Tooltip content={content} position={position} className={className} focusable>
      <span
        className={clsx(
          'inline-flex items-center justify-center rounded-full',
          'text-slate-400 hover:text-slate-600 focus:text-slate-600',
          'cursor-help transition-colors'
        )}
        aria-label={ariaLabel}
      >
        <HelpCircle className={iconSize} aria-hidden="true" />
      </span>
    </Tooltip>
  );
}

// Field label with optional help tooltip
interface LabelWithHelpProps {
  label: string;
  htmlFor?: string;
  help?: string;
  required?: boolean;
  className?: string;
}

export function LabelWithHelp({
  label,
  htmlFor,
  help,
  required,
  className,
}: LabelWithHelpProps) {
  return (
    <span
      className={clsx(
        'flex items-center gap-1.5 text-sm font-medium text-slate-700',
        className
      )}
    >
      <label htmlFor={htmlFor}>
        {label}
        {required && <span className="text-red-500" aria-hidden="true">*</span>}
        {required && <span className="sr-only">(required)</span>}
      </label>
      {help && <HelpTooltip content={help} ariaLabel={`Help for ${label}`} />}
    </span>
  );
}
