import { useLayoutEffect, useRef } from "react";

/**
 * Runs `reset` when the screen is hidden or unmounted.
 *
 * With `cacheComponents`, Next.js does not unmount a route on navigation: it hides it with
 * React `<Activity>` and keeps its state, so a later visit shows it as it was left. Use this
 * hook for transient state that must not survive a visit (open dialogs, success or error
 * messages, a finished flow). Hiding runs effect cleanups, and a layout effect cleans up
 * before the screen is hidden, so the stale state never flashes on return.
 */
export function useResetOnHide(reset: () => void) {
  const resetRef = useRef(reset);

  useLayoutEffect(() => {
    resetRef.current = reset;
  });

  useLayoutEffect(() => () => resetRef.current(), []);
}
