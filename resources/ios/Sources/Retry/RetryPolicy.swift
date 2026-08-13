import Foundation

struct RetryPolicy {
    let times: Int
    let delay: Int
    let multiplier: Double
    let maxDelay: Int?
    let statuses: Set<Int>
    var maxAttempts: Int { times + 1 }
}

func retryPolicy(from value: Any?) -> RetryPolicy? {
    guard let retry = value as? [String: Any] else { return nil }
    let defaults: Set<Int> = [408, 429, 500, 502, 503, 504]
    let custom = Set((retry["statuses"] as? [Any] ?? []).compactMap { ($0 as? NSNumber)?.intValue })
    return RetryPolicy(
        times: (retry["times"] as? NSNumber)?.intValue ?? 3,
        delay: (retry["delay"] as? NSNumber)?.intValue ?? 500,
        multiplier: (retry["multiplier"] as? NSNumber)?.doubleValue ?? 2,
        maxDelay: (retry["max_delay"] as? NSNumber)?.intValue,
        statuses: custom.isEmpty ? defaults : custom
    )
}
