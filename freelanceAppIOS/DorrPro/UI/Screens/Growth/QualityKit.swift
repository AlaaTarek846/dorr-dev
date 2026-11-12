import SwiftUI

// freelanceApp `ui/screens/QualityKit.kt`.

// FS-601 / FS-602 / FS-604 (quality & trust): the pieces the verified works, the skill levels and the reputation screen share — a skill
// level as a pill and as a four-step meter, the «Verified by Dorr» badge and a tinted note banner.

/// The green of «verified» / «accepted» across the quality screens.
let QualityGreen = Color(hex: 0x16A34A)
let QualityAmber = Color(hex: 0xD97706)
let QualityRed = Color(hex: 0xDC2626)
let QualitySlate = Color(hex: 0x64748B)

/// The four levels of a skill badge, lowest first.
let SKILL_LEVELS = ["beginner", "intermediate", "advanced", "expert"]

func skillLevelLabel(_ level: String?) -> String {
    switch level {
    case "intermediate": return L("skl_level_intermediate")
    case "advanced": return L("skl_level_advanced")
    case "expert": return L("skl_level_expert")
    default: return L("skl_level_beginner")
    }
}

/// Each level has its colour: blue, teal, violet and gold.
func skillLevelColor(_ level: String?) -> Color {
    switch level {
    case "intermediate": return Tints.teal
    case "advanced": return Tints.violet
    case "expert": return Tints.amber
    default: return Tints.blue
    }
}

/// A skill's level as a pill in the level's colour, with a seal icon.
struct SkillLevelPill: View {
    @Environment(\.wa) private var wa
    let level: String?

    var body: some View {
        let tint = skillLevelColor(level)
        HStack(spacing: 4) {
            Image(systemName: "checkmark.shield.fill").font(.system(size: 10, weight: .bold)).frame(width: 13, height: 13)
            Text(verbatim: skillLevelLabel(level)).font(.dorr(11.5, .heavy)).lineLimit(1)
        }
        .foregroundStyle(tint)
        .padding(.horizontal, 10)
        .padding(.vertical, 4)
        .background(tint.opacity(wa.night ? 0.22 : 0.12), in: Capsule())
    }
}

/// Where a level stands among the four: four bars filled up to it in the level's colour, the four names under them (the reached ones in
/// ink). `level` nil draws them all empty.
struct SkillLevelMeter: View {
    @Environment(\.wa) private var wa
    let level: String?

    var body: some View {
        let reached = level == nil ? -1 : max(SKILL_LEVELS.firstIndex(of: level ?? "") ?? 0, 0)
        let tint = skillLevelColor(level)
        let track = wa.settingsMut.opacity(0.16)
        VStack(spacing: 6) {
            HStack(spacing: 5) {
                ForEach(0..<SKILL_LEVELS.count, id: \.self) { i in
                    Capsule().fill(i <= reached ? tint : track).frame(height: 6).frame(maxWidth: .infinity)
                }
            }
            HStack(spacing: 5) {
                ForEach(0..<SKILL_LEVELS.count, id: \.self) { i in
                    Text(verbatim: skillLevelLabel(SKILL_LEVELS[i]))
                        .font(.dorr(10.5, i == reached ? .heavy : .semibold))
                        .foregroundStyle(i == reached ? tint : (i < reached ? wa.settingsInk : wa.settingsMut))
                        .lineLimit(1)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
            }
        }
        .frame(maxWidth: .infinity)
    }
}

/// "2026-03" → "March 2026" in the app's language (Latin digits); the text itself when it can't be read.
func monthLabel(_ yearMonth: String?) -> String {
    guard let yearMonth, !yearMonth.isEmpty else { return "" }
    let parse = DateFormatter()
    parse.locale = Locale(identifier: "en_US_POSIX")
    parse.dateFormat = "yyyy-MM"
    guard let date = parse.date(from: yearMonth) else { return yearMonth }
    let out = DateFormatter()
    out.locale = AppLocale.locale
    out.dateFormat = "MMMM yyyy"
    return out.string(from: date)
}

/// «Verified by Dorr · finished {month}»: a green shield and the line, on a soft green pill (`compact` for a card, smaller).
struct VerifiedBadge: View {
    @Environment(\.wa) private var wa
    let verifiedOn: String?
    var compact = false

    var body: some View {
        let text = (verifiedOn ?? "").isEmpty ? L("pfv_badge") : L("pfv_badge_on", monthLabel(verifiedOn))
        HStack(spacing: 4) {
            Image(systemName: "checkmark.shield.fill").font(.system(size: compact ? 10 : 12, weight: .bold))
            Text(verbatim: text).font(.dorr(compact ? 11 : 12, .bold)).lineLimit(1)
        }
        .foregroundStyle(QualityGreen)
        .padding(.horizontal, compact ? 8 : 10)
        .padding(.vertical, compact ? 3 : 5)
        .background(QualityGreen.opacity(wa.night ? 0.22 : 0.11), in: Capsule())
    }
}

/// A note on a soft tint with a hairline: an icon on a disc, an optional bold title and the text (a warning, a success, a hint).
struct NoteBanner: View {
    @Environment(\.wa) private var wa
    let icon: String
    let text: String
    let tint: Color
    var title: String?

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 16, style: .continuous)
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: icon).font(.system(size: 14, weight: .semibold)).foregroundStyle(tint)
                .frame(width: 30, height: 30)
                .background(tint.opacity(0.16), in: Circle())
            VStack(alignment: .leading, spacing: 2) {
                if let title { Text(verbatim: title).font(.dorr(13.5, .heavy)).foregroundStyle(wa.settingsInk) }
                Text(verbatim: text).font(.dorr(12.5)).foregroundStyle(title == nil ? wa.settingsInk : wa.settingsMut).lineSpacing(3)
                    .fixedSize(horizontal: false, vertical: true)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(tint.opacity(wa.night ? 0.16 : 0.08), in: shape)
        .overlay(shape.stroke(tint.opacity(0.25), lineWidth: 1))
    }
}
