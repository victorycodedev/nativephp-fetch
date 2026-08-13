export function normalizeRetry(options = 3) {
  const configuration =
    typeof options === "number" ? { times: options } : options;
  const {
    times = 3,
    delay = 500,
    multiplier = 2,
    maxDelay = 30000,
    statuses = [],
  } = configuration;

  if (!Number.isInteger(times) || times < 0)
    throw new TypeError("Fetch retry times must be a non-negative integer.");
  if (!Number.isInteger(delay) || delay < 0)
    throw new TypeError("Fetch retry delay must be a non-negative integer.");
  if (typeof multiplier !== "number" || multiplier < 1)
    throw new TypeError("Fetch retry multiplier must be at least 1.0.");
  if (maxDelay !== null && (!Number.isInteger(maxDelay) || maxDelay < delay)) {
    throw new TypeError("Fetch retry maxDelay must be null or at least delay.");
  }
  if (
    !Array.isArray(statuses) ||
    statuses.some(
      (status) => !Number.isInteger(status) || status < 100 || status > 599,
    )
  ) {
    throw new TypeError(
      "Fetch retry statuses must be valid HTTP status integers.",
    );
  }

  return {
    times,
    delay,
    multiplier,
    max_delay: maxDelay,
    statuses: [...new Set(statuses)],
  };
}
