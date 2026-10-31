import { Eye, EyeOff } from 'lucide-react';
import type { ComponentProps, Ref } from 'react';
import { useState } from 'react';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { cn } from '@/lib/utils';

export default function PasswordInput({
    className,
    ref,
    hasSavedValue = false,
    ...props
}: Omit<ComponentProps<'input'>, 'type'> & {
    ref?: Ref<HTMLInputElement>;
    hasSavedValue?: boolean;
}) {
    const [showPassword, setShowPassword] = useState(false);
    const [focused, setFocused] = useState(false);
    const showSavedValue = hasSavedValue && !focused && !props.value;
    const passwordVisible = showPassword && !showSavedValue;

    return (
        <InputGroup className={cn(className, 'flex')}>
            <InputGroupInput
                type={passwordVisible ? 'text' : 'password'}
                ref={ref}
                {...props}
                // The saved indicator is masked by the browser and never submitted.
                name={showSavedValue ? undefined : props.name}
                value={showSavedValue ? ' '.repeat(24) : props.value}
                onFocus={(event) => {
                    setFocused(true);
                    props.onFocus?.(event);
                }}
                onBlur={(event) => {
                    setFocused(false);
                    props.onBlur?.(event);
                }}
            />
            <InputGroupAddon align="inline-end">
                <InputGroupButton
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    onClick={() => setShowPassword((prev) => !prev)}
                    aria-label={
                        passwordVisible
                            ? 'Nascondi password'
                            : 'Mostra password'
                    }
                    aria-pressed={passwordVisible}
                    tabIndex={props.tabIndex}
                    disabled={props.disabled || showSavedValue}
                >
                    {passwordVisible ? (
                        <EyeOff className="size-4" />
                    ) : (
                        <Eye className="size-4" />
                    )}
                </InputGroupButton>
            </InputGroupAddon>
        </InputGroup>
    );
}
