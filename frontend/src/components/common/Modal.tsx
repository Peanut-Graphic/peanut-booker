import { Fragment, type ReactNode, useEffect, useRef, useCallback, useId } from 'react';
import { X } from 'lucide-react';
import { clsx } from 'clsx';
import Button from './Button';

// Focusable element selectors
const FOCUSABLE_SELECTORS = [
  'button:not([disabled])',
  'a[href]',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ');

interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  title?: string;
  description?: string;
  children: ReactNode;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  showClose?: boolean;
  className?: string;
  /** Element to focus when modal opens. Defaults to first focusable element. */
  initialFocusRef?: React.RefObject<HTMLElement>;
}

export default function Modal({
  isOpen,
  onClose,
  title,
  description,
  children,
  size = 'md',
  showClose = true,
  className,
  initialFocusRef,
}: ModalProps) {
  const modalRef = useRef<HTMLDivElement>(null);
  const closeButtonRef = useRef<HTMLButtonElement>(null);
  const previousActiveElement = useRef<HTMLElement | null>(null);
  const titleId = useId();
  const descriptionId = useId();

  const sizes = {
    sm: 'max-w-md',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
    xl: 'max-w-4xl',
  };

  // Get all focusable elements within the modal
  const getFocusableElements = useCallback(() => {
    if (!modalRef.current) return [];
    return Array.from(
      modalRef.current.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTORS)
    ).filter((el) => el.offsetParent !== null); // Only visible elements
  }, []);

  // Focus trap - keep focus within modal
  const handleKeyDown = useCallback(
    (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        onClose();
        return;
      }

      if (e.key !== 'Tab') return;

      const focusableElements = getFocusableElements();
      if (focusableElements.length === 0) return;

      const firstElement = focusableElements[0];
      const lastElement = focusableElements[focusableElements.length - 1];

      // Shift+Tab on first element -> go to last
      if (e.shiftKey && document.activeElement === firstElement) {
        e.preventDefault();
        lastElement.focus();
      }
      // Tab on last element -> go to first
      else if (!e.shiftKey && document.activeElement === lastElement) {
        e.preventDefault();
        firstElement.focus();
      }
    },
    [onClose, getFocusableElements]
  );

  // Handle focus management and body scroll lock
  useEffect(() => {
    if (!isOpen) return;

    // Store the previously focused element
    previousActiveElement.current = document.activeElement as HTMLElement;

    // Lock body scroll
    const originalOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    // Set initial focus
    const setInitialFocus = () => {
      if (initialFocusRef?.current) {
        initialFocusRef.current.focus();
      } else if (closeButtonRef.current) {
        closeButtonRef.current.focus();
      } else {
        const focusableElements = getFocusableElements();
        if (focusableElements.length > 0) {
          focusableElements[0].focus();
        }
      }
    };

    // Delay focus to ensure modal is rendered
    const timeoutId = setTimeout(setInitialFocus, 10);

    // Add keyboard listener
    document.addEventListener('keydown', handleKeyDown);

    return () => {
      clearTimeout(timeoutId);
      document.removeEventListener('keydown', handleKeyDown);
      document.body.style.overflow = originalOverflow;

      // Restore focus to previous element
      if (previousActiveElement.current && previousActiveElement.current.focus) {
        previousActiveElement.current.focus();
      }
    };
  }, [isOpen, handleKeyDown, getFocusableElements, initialFocusRef]);

  if (!isOpen) return null;

  return (
    <Fragment>
      {/* Backdrop */}
      <button
        type="button"
        className="fixed inset-0 bg-black/50 z-[999998] transition-opacity"
        onClick={onClose}
        aria-label="Dismiss modal"
      />

      {/* Modal */}
      <div className="fixed inset-0 z-[999999] overflow-y-auto">
        <div className="flex min-h-[100dvh] items-end justify-center p-3 sm:items-center sm:p-4">
          <div
            ref={modalRef}
            role="dialog"
            aria-modal="true"
            aria-labelledby={title ? titleId : undefined}
            aria-describedby={description ? descriptionId : undefined}
            className={clsx(
              'relative w-full max-h-[calc(100dvh-1.5rem)] overflow-hidden rounded-t-2xl bg-white shadow-xl transform transition-all animate-scale-in sm:max-h-[calc(100dvh-2rem)] sm:rounded-xl',
              sizes[size],
              className
            )}
            onClick={(e) => e.stopPropagation()}
          >
            {/* Header */}
            {(title || showClose) && (
              <div className="flex items-start justify-between p-5 border-b border-slate-200">
                <div>
                  {title && (
                    <h2
                      id={titleId}
                      className="text-lg font-semibold text-slate-900"
                    >
                      {title}
                    </h2>
                  )}
                  {description && (
                    <p id={descriptionId} className="text-sm text-slate-500 mt-1">
                      {description}
                    </p>
                  )}
                </div>
                {showClose && (
                  <button
                    ref={closeButtonRef}
                    onClick={onClose}
                    className={clsx(
                      'text-slate-400 hover:text-slate-600 transition-colors',
                      'min-h-11 min-w-11 rounded-lg p-3 -m-3 sm:p-2 sm:-m-2',
                      'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500'
                    )}
                    aria-label="Close modal"
                  >
                    <X className="w-5 h-5" aria-hidden="true" />
                  </button>
                )}
              </div>
            )}

            {/* Content */}
            <div className="max-h-[calc(100dvh-9rem)] overflow-y-auto p-5 sm:max-h-[calc(100dvh-10rem)]">{children}</div>
          </div>
        </div>
      </div>
    </Fragment>
  );
}

interface ConfirmModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  message: string;
  confirmText?: string;
  cancelText?: string;
  variant?: 'danger' | 'primary';
  loading?: boolean;
}

export function ConfirmModal({
  isOpen,
  onClose,
  onConfirm,
  title,
  message,
  confirmText = 'Confirm',
  cancelText = 'Cancel',
  variant = 'primary',
  loading = false,
}: ConfirmModalProps) {
  const cancelButtonRef = useRef<HTMLButtonElement>(null);
  const confirmButtonRef = useRef<HTMLButtonElement>(null);

  // For danger actions, focus cancel button (safer default)
  // For primary actions, focus confirm button
  const initialFocusRef = variant === 'danger' ? cancelButtonRef : confirmButtonRef;

  // Handle Enter key on confirm action
  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      // Enter confirms when not on cancel button and not loading
      if (
        e.key === 'Enter' &&
        document.activeElement !== cancelButtonRef.current &&
        !loading
      ) {
        e.preventDefault();
        onConfirm();
      }
    },
    [onConfirm, loading]
  );

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={title}
      size="sm"
      initialFocusRef={initialFocusRef as React.RefObject<HTMLElement>}
    >
      <div onKeyDown={handleKeyDown}>
        <p className="text-slate-600 mb-6">{message}</p>
        <div className="flex justify-end gap-3">
          <Button
            ref={cancelButtonRef}
            variant="outline"
            onClick={onClose}
            disabled={loading}
          >
            {cancelText}
          </Button>
          <Button
            ref={confirmButtonRef}
            variant={variant === 'danger' ? 'danger' : 'primary'}
            onClick={onConfirm}
            loading={loading}
          >
            {confirmText}
          </Button>
        </div>
      </div>
    </Modal>
  );
}
