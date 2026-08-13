import Foundation

enum FetchNativeError: Error {
    case invalidURL
    case invalidBody
    case invalidFile
    case fileNotFound(String)
    case multipartBuildFailed
    case download(code: String, message: String)
}
