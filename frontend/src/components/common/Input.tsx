import { forwardRef, useId, type InputHTMLAttributes, type TextareaHTMLAttributes, type ReactNode } from 'react';
import { clsx } from 'clsx';

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string;
  hint?: string;
  leftIcon?: ReactNode;
}

const Input = forwardRef<HTMLInputElement, InputProps>(
  ({ className, label, error, hint, leftIcon, id, ...props }, ref) => {
    const generatedId = useId();
    const inputId = id || props.name || generatedId;

    return (
      <div className="space-y-1">
        {label && (
          <label htmlFor={inputId} className="block text-sm font-medium text-slate-700">
            {label}
          </label>
        )}
        <div className="relative">
          {leftIcon && (
            <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
              {leftIcon}
            </div>
          )}
          <input
            ref={ref}
            id={inputId}
            className={clsx(
              'w-full py-2 border rounded-lg text-base sm:text-sm transition-colors text-slate-900 dark:text-slate-100',
              leftIcon ? 'pl-10 pr-3' : 'px-3',
              'focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent',
              error
                ? 'border-red-300 focus:ring-red-500'
                : 'border-slate-300 hover:border-slate-400',
              className
            )}
            {...props}
          />
        </div>
        {hint && !error && (
          <p className="text-xs text-slate-500">{hint}</p>
        )}
        {error && (
          <p className="text-xs text-red-600">{error}</p>
        )}
      </div>
    );
  }
);

Input.displayName = 'Input';

interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string;
  error?: string;
  hint?: string;
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(
  ({ className, label, error, hint, id, ...props }, ref) => {
    const generatedId = useId();
    const inputId = id || props.name || generatedId;

    return (
      <div className="space-y-1">
        {label && (
          <label htmlFor={inputId} className="block text-sm font-medium text-slate-700">
            {label}
          </label>
        )}
        <textarea
          ref={ref}
          id={inputId}
          className={clsx(
            'w-full px-3 py-2 border rounded-lg text-base sm:text-sm transition-colors resize-y text-slate-900 dark:text-slate-100',
            'focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent',
            error
              ? 'border-red-300 focus:ring-red-500'
              : 'border-slate-300 hover:border-slate-400',
            className
          )}
          {...props}
        />
        {hint && !error && (
          <p className="text-xs text-slate-500">{hint}</p>
        )}
        {error && (
          <p className="text-xs text-red-600">{error}</p>
        )}
      </div>
    );
  }
);

Textarea.displayName = 'Textarea';

export default Input;
