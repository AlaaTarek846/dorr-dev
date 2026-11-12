import LiveKit

// The whole phone's screen in a call (androidApp shares it through MediaProjection). iOS hands the screen's frames
// to this broadcast extension once I pick DORR in the system's sheet; LiveKit's uploader passes them to the app over
// the shared app group, and the app publishes them as the call's screen share.
final class SampleHandler: LKSampleHandler {}
