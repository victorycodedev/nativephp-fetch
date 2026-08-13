import Foundation

enum NativeEventDispatcher {
    static func dispatch(name: String, payload: [String: Any]) {
        DispatchQueue.main.async {
            LaravelBridge.shared.send?(name, payload)
        }
    }
}
