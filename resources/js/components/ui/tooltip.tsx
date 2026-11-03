import * as TooltipPrimitive from "@radix-ui/react-tooltip"
import * as React from "react"

import { cn } from "@/lib/utils"

export const TOOLTIP_DELAY = 700

const TooltipKeyboardNavigation = React.createContext<React.RefObject<boolean> | null>(null)

function TooltipProvider({
  children,
  ...props
}: Omit<
  React.ComponentProps<typeof TooltipPrimitive.Provider>,
  "delayDuration" | "skipDelayDuration"
>) {
  const keyboardNavigation = React.useRef(false)

  React.useEffect(() => {
    function reset() {
      keyboardNavigation.current = false
    }
    function onKeyDown(event: KeyboardEvent) {
      reset()
      // Dialog autofocus and restored focus must not behave like keyboard navigation.
      keyboardNavigation.current =
        !event.altKey && !event.ctrlKey && !event.metaKey &&
        ["Tab", "ArrowUp", "ArrowDown", "ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)
    }
    document.addEventListener("keydown", onKeyDown, true)
    document.addEventListener("keyup", reset, true)
    document.addEventListener("pointerdown", reset, true)
    window.addEventListener("blur", reset)
    return () => {
      reset()
      document.removeEventListener("keydown", onKeyDown, true)
      document.removeEventListener("keyup", reset, true)
      document.removeEventListener("pointerdown", reset, true)
      window.removeEventListener("blur", reset)
    }
  }, [])

  return (
    <TooltipKeyboardNavigation.Provider value={keyboardNavigation}>
      <TooltipPrimitive.Provider
        data-slot="tooltip-provider"
        {...props}
        delayDuration={TOOLTIP_DELAY}
        skipDelayDuration={0}
      >
        {children}
      </TooltipPrimitive.Provider>
    </TooltipKeyboardNavigation.Provider>
  )
}

function Tooltip({
  ...props
}: Omit<React.ComponentProps<typeof TooltipPrimitive.Root>, "delayDuration" | "defaultOpen">) {
  return (
    <TooltipPrimitive.Root
      data-slot="tooltip"
      {...props}
      delayDuration={TOOLTIP_DELAY}
      defaultOpen={false}
    />
  )
}

function TooltipTrigger({
  onFocus,
  ...props
}: React.ComponentProps<typeof TooltipPrimitive.Trigger>) {
  const keyboardNavigation = React.useContext(TooltipKeyboardNavigation)
  return (
    <TooltipPrimitive.Trigger
      data-slot="tooltip-trigger"
      {...props}
      onFocus={(event) => {
        onFocus?.(event)
        if (!keyboardNavigation?.current) event.preventDefault()
      }}
    />
  )
}

function TooltipContent({
  className,
  sideOffset = 4,
  children,
  ...props
}: React.ComponentProps<typeof TooltipPrimitive.Content>) {
  return (
    <TooltipPrimitive.Portal>
      <TooltipPrimitive.Content
        data-slot="tooltip-content"
        sideOffset={sideOffset}
        className={cn(
          "bg-primary text-primary-foreground animate-in fade-in-0 zoom-in-95 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 max-w-sm rounded-md px-3 py-1.5 text-xs",
          className
        )}
        {...props}
      >
        {children}
        <TooltipPrimitive.Arrow className="bg-primary fill-primary z-50 size-2.5 translate-y-[calc(-50%_-_2px)] rotate-45 rounded-[2px]" />
      </TooltipPrimitive.Content>
    </TooltipPrimitive.Portal>
  )
}

export { Tooltip, TooltipTrigger, TooltipContent, TooltipProvider }
