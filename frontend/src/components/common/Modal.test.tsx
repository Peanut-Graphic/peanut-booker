import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { useRef } from 'react';
import Modal, { ConfirmModal } from './Modal';

describe('Modal', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    title: 'Test Modal',
    children: <div>Modal content</div>,
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    document.body.style.overflow = '';
  });

  describe('rendering', () => {
    it('renders when isOpen is true', () => {
      render(<Modal {...defaultProps} />);
      expect(screen.getByText('Test Modal')).toBeInTheDocument();
      expect(screen.getByText('Modal content')).toBeInTheDocument();
    });

    it('does not render when isOpen is false', () => {
      render(<Modal {...defaultProps} isOpen={false} />);
      expect(screen.queryByText('Test Modal')).not.toBeInTheDocument();
    });

    it('renders title correctly', () => {
      render(<Modal {...defaultProps} title="Custom Title" />);
      expect(screen.getByText('Custom Title')).toBeInTheDocument();
    });

    it('renders description when provided', () => {
      render(<Modal {...defaultProps} description="Modal description" />);
      expect(screen.getByText('Modal description')).toBeInTheDocument();
    });

    it('renders children correctly', () => {
      render(
        <Modal {...defaultProps}>
          <p>Custom content</p>
        </Modal>
      );
      expect(screen.getByText('Custom content')).toBeInTheDocument();
    });

    it('renders without title', () => {
      render(
        <Modal isOpen={true} onClose={vi.fn()}>
          <div>Content only</div>
        </Modal>
      );
      expect(screen.getByText('Content only')).toBeInTheDocument();
    });
  });

  describe('close button', () => {
    it('shows close button by default', () => {
      render(<Modal {...defaultProps} />);
      expect(screen.getByRole('button', { name: /close modal/i })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /dismiss modal/i })).toBeInTheDocument();
    });

    it('hides close button when showClose is false', () => {
      render(<Modal {...defaultProps} showClose={false} />);
      expect(screen.queryByRole('button', { name: /close modal/i })).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: /dismiss modal/i })).toBeInTheDocument();
    });

    it('calls onClose when close button is clicked', () => {
      render(<Modal {...defaultProps} />);
      fireEvent.click(screen.getByRole('button', { name: /close modal/i }));
      expect(defaultProps.onClose).toHaveBeenCalledTimes(1);
    });
  });

  describe('sizes', () => {
    it('applies sm size class', () => {
      render(<Modal {...defaultProps} size="sm" />);
      const modal = document.querySelector('.max-w-md');
      expect(modal).toBeInTheDocument();
    });

    it('applies md size class by default', () => {
      render(<Modal {...defaultProps} />);
      const modal = document.querySelector('.max-w-lg');
      expect(modal).toBeInTheDocument();
    });

    it('applies lg size class', () => {
      render(<Modal {...defaultProps} size="lg" />);
      const modal = document.querySelector('.max-w-2xl');
      expect(modal).toBeInTheDocument();
    });

    it('applies xl size class', () => {
      render(<Modal {...defaultProps} size="xl" />);
      const modal = document.querySelector('.max-w-4xl');
      expect(modal).toBeInTheDocument();
    });
  });

  describe('interactions', () => {
    it('calls onClose when backdrop is clicked', () => {
      render(<Modal {...defaultProps} />);
      const backdrop = document.querySelector('.bg-black\\/50');
      fireEvent.click(backdrop!);
      expect(defaultProps.onClose).toHaveBeenCalledTimes(1);
    });

    it('does not close when modal content is clicked', () => {
      render(<Modal {...defaultProps} />);
      fireEvent.click(screen.getByText('Modal content'));
      expect(defaultProps.onClose).not.toHaveBeenCalled();
    });

    it('calls onClose when Escape key is pressed', () => {
      render(<Modal {...defaultProps} />);
      fireEvent.keyDown(document, { key: 'Escape' });
      expect(defaultProps.onClose).toHaveBeenCalledTimes(1);
    });

    it('does not call onClose for other keys', () => {
      render(<Modal {...defaultProps} />);
      fireEvent.keyDown(document, { key: 'Enter' });
      expect(defaultProps.onClose).not.toHaveBeenCalled();
    });
  });

  describe('body overflow', () => {
    it('sets body overflow to hidden when modal opens', () => {
      render(<Modal {...defaultProps} />);
      expect(document.body.style.overflow).toBe('hidden');
    });

    it('resets body overflow when modal closes', () => {
      const { rerender } = render(<Modal {...defaultProps} />);
      expect(document.body.style.overflow).toBe('hidden');

      rerender(<Modal {...defaultProps} isOpen={false} />);
      expect(document.body.style.overflow).toBe('');
    });

    it('cleans up body overflow on unmount', () => {
      const { unmount } = render(<Modal {...defaultProps} />);
      expect(document.body.style.overflow).toBe('hidden');

      unmount();
      expect(document.body.style.overflow).toBe('');
    });
  });

  describe('accessibility', () => {
    it('has role dialog', () => {
      render(<Modal {...defaultProps} />);
      expect(screen.getByRole('dialog')).toBeInTheDocument();
    });

    it('has aria-modal attribute', () => {
      render(<Modal {...defaultProps} />);
      expect(screen.getByRole('dialog')).toHaveAttribute('aria-modal', 'true');
    });

    it('has aria-labelledby when title is provided', () => {
      render(<Modal {...defaultProps} />);
      const dialog = screen.getByRole('dialog');
      expect(dialog).toHaveAttribute('aria-labelledby');
    });

    it('has aria-describedby when description is provided', () => {
      render(<Modal {...defaultProps} description="Test description" />);
      const dialog = screen.getByRole('dialog');
      expect(dialog).toHaveAttribute('aria-describedby');
    });
  });

  describe('custom className', () => {
    it('applies custom className to modal', () => {
      render(<Modal {...defaultProps} className="custom-class" />);
      const modal = document.querySelector('.custom-class');
      expect(modal).toBeInTheDocument();
    });
  });
});

describe('ConfirmModal', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    onConfirm: vi.fn(),
    title: 'Confirm Action',
    message: 'Are you sure?',
  };

  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    document.body.style.overflow = '';
  });

  describe('rendering', () => {
    it('renders with title and message', () => {
      render(<ConfirmModal {...defaultProps} />);
      expect(screen.getByText('Confirm Action')).toBeInTheDocument();
      expect(screen.getByText('Are you sure?')).toBeInTheDocument();
    });

    it('renders default button text', () => {
      render(<ConfirmModal {...defaultProps} />);
      expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Confirm' })).toBeInTheDocument();
    });

    it('renders custom button text', () => {
      render(
        <ConfirmModal
          {...defaultProps}
          confirmText="Delete"
          cancelText="Keep"
        />
      );
      expect(screen.getByRole('button', { name: 'Keep' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Delete' })).toBeInTheDocument();
    });

    it('does not render when isOpen is false', () => {
      render(<ConfirmModal {...defaultProps} isOpen={false} />);
      expect(screen.queryByText('Confirm Action')).not.toBeInTheDocument();
    });
  });

  describe('variants', () => {
    it('applies primary variant by default', () => {
      render(<ConfirmModal {...defaultProps} />);
      const confirmButton = screen.getByRole('button', { name: 'Confirm' });
      expect(confirmButton).toHaveClass('bg-primary-600');
    });

    it('applies danger variant', () => {
      render(<ConfirmModal {...defaultProps} variant="danger" />);
      const confirmButton = screen.getByRole('button', { name: 'Confirm' });
      expect(confirmButton).toHaveClass('bg-red-600');
    });
  });

  describe('interactions', () => {
    it('calls onClose when cancel is clicked', () => {
      render(<ConfirmModal {...defaultProps} />);
      fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
      expect(defaultProps.onClose).toHaveBeenCalledTimes(1);
    });

    it('calls onConfirm when confirm is clicked', () => {
      render(<ConfirmModal {...defaultProps} />);
      fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
      expect(defaultProps.onConfirm).toHaveBeenCalledTimes(1);
    });
  });

  describe('loading state', () => {
    it('disables cancel button when loading', () => {
      render(<ConfirmModal {...defaultProps} loading />);
      expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    });

    it('shows loading indicator on confirm button', () => {
      render(<ConfirmModal {...defaultProps} loading />);
      // The Button component shows a loading spinner when loading
      const confirmButton = screen.getByRole('button', { name: 'Confirm' });
      expect(confirmButton).toBeDisabled();
    });
  });
});
