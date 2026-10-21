"""Colours the client's freelance requirements file by what is built, and updates the progress table.

Run `python docs/modules/freelance/make_status.py` after a batch of work. It reads `docs/freelance_tasks.md`:

  * green  = the item is covered by a task ticked `- [x]`;
  * red    = covered by a task still open;
  * grey   = no task covers it (merged into another item, removed, or rejected — see requirements-analysis.md).

The numbers of an item come from the `📄` part of each task ("📄 028–035، 042"). Writes
`*_status.docx` next to the original, which stays untouched, and refreshes the progress table
between the `progress` markers of `docs/freelance_tasks.md`. No third-party packages: the docx is
edited as plain XML.
"""
import re
import sys
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
TASKS = HERE.parents[1] / "freelance_tasks.md"
SOURCE = HERE / "DORR_Freelance_Platform_Requirements_v4_405.docx"
TARGET = HERE / "DORR_Freelance_Platform_Requirements_v4_405_status.docx"

GREEN, RED, GREY = "D9F2E0", "FBDADA", "ECECEC"
BATCHES = {
    0: "0 — التأسيس", 1: "1 — الكتالوج وملف المحترف", 2: "2 — المشاريع", 3: "3 — العروض", 4: "4 — الاتفاق والمراحل",
    5: "5 — الدفع المحجوز والتحرير", 6: "6 — التسليم", 7: "7 — التقييم والنزاعات", 8: "8 — الإدارة والتشغيل",
    9: "9 — الذكاء الاصطناعي", 10: "10 — موقع المحترفين", 11: "11 — داشبورد المحترف", 12: "12 — بنية وتشغيل وقانوني",
    "L": "لاحقاً", "Z": "الشركات والمؤسسات (اختياري)",
}


def item_numbers(spec):
    """'028–035، 042' → {'028', …, '035', '042'} (three digits, as in the requirements file)."""
    out = set()
    for a, b in re.findall(r"(\d{3})(?:\s*[–-]\s*(\d{3}))?", spec):
        lo, hi = int(a), int(b or a)
        out.update(f"{i:03d}" for i in range(lo, hi + 1))
    return out


def tasks():
    """[(id, batch, done, {item numbers})] for every task line of the tasks file (struck-through = moved, skipped)."""
    out = []
    for raw in TASKS.read_text(encoding="utf-8").splitlines():
        m = re.match(r"-\s*\[([ xX])\]\s*(~~)?\*\*FL-([A-Z]?\d+)\*\*", raw.strip())
        if not m or m.group(2):
            continue
        tid = m.group(3)
        batch = tid[0] if tid[0] in "LZ" else int(tid) // 100
        spec = raw.split("📄", 1)[1].split("👤")[0] if "📄" in raw else ""
        spec = re.split(r"\(\d+ بنود", spec)[0]
        out.append((f"FL-{tid}", batch, m.group(1) in "xX", item_numbers(spec)))
    return out


def classify(task_list):
    done, planned = set(), set()
    for _, _, ticked, nums in task_list:
        (done if ticked else planned).update(nums)
    return done, planned - done


def shade(paragraph_xml, fill):
    """Puts a background colour on a paragraph (replacing one it already has)."""
    shd = f'<w:shd w:val="clear" w:color="auto" w:fill="{fill}"/>'
    paragraph_xml = re.sub(r"<w:shd\b[^>]*/>", "", paragraph_xml, count=1)
    if "<w:pPr>" in paragraph_xml or "<w:pPr " in paragraph_xml:
        # w:shd must come before these siblings (schema order); otherwise at the end of pPr.
        successors = r"<w:(tabs|suppressAutoHyphens|kinsoku|wordWrap|overflowPunct|topLinePunct|autoSpaceDE|autoSpaceDN|bidi|adjustRightInd|snapToGrid|spacing|ind|contextualSpacing|mirrorIndents|suppressOverlap|jc|textDirection|textAlignment|textboxTightWrap|outlineLvl|divId|cnfStyle|rPr|sectPr|pPrChange)\b"
        ppr_start = re.search(r"<w:pPr\b[^>]*>", paragraph_xml)
        ppr_end = paragraph_xml.index("</w:pPr>", ppr_start.end())
        inner = paragraph_xml[ppr_start.end():ppr_end]
        found = re.search(successors, inner)
        at = ppr_start.end() + (found.start() if found else len(inner))
        return paragraph_xml[:at] + shd + paragraph_xml[at:]
    return re.sub(r"(<w:p\b[^>]*>)", r"\1<w:pPr>" + shd + "</w:pPr>", paragraph_xml, count=1)


def summary_paragraph(done, planned, grey):
    def run(text, bold=False):
        return f'<w:r><w:rPr>{"<w:b/>" if bold else ""}<w:rtl/></w:rPr><w:t xml:space="preserve">{text}</w:t></w:r>'
    text = (
        f"حالة التنفيذ — أخضر: منجز ({len(done)}) · أحمر: مخطط ولم يُنجز ({len(planned)}) · "
        f"رمادي: مدموج أو محذوف أو مرفوض ({len(grey)}). المرجع: docs/freelance_tasks.md"
    )
    return f'<w:p><w:pPr><w:shd w:val="clear" w:color="auto" w:fill="FFF4CC"/><w:bidi/></w:pPr>{run(text, True)}</w:p>'


def build(done, planned):
    with zipfile.ZipFile(SOURCE) as zin:
        xml = zin.read("word/document.xml").decode("utf-8")
        counts = {"green": 0, "red": 0, "grey": 0}
        seen = set()
        greys = set()

        def paint(match):
            para = match.group(0)
            text = "".join(re.findall(r"<w:t[^>]*>([^<]*)</w:t>", para))
            m = re.match(r"\s*(\d{3})\s*\|", text)
            if not m or m.group(1) in seen:
                return para
            num = m.group(1)
            seen.add(num)
            if num in done:
                counts["green"] += 1
                return shade(para, GREEN)
            if num in planned:
                counts["red"] += 1
                return shade(para, RED)
            counts["grey"] += 1
            greys.add(num)
            return shade(para, GREY)

        xml = re.sub(r"<w:p\b[^>]*>.*?</w:p>", paint, xml, flags=re.S)
        body = re.search(r"<w:body>", xml)
        xml = xml[:body.end()] + summary_paragraph(done & seen, planned & seen, greys) + xml[body.end():]
        with zipfile.ZipFile(TARGET, "w", zipfile.ZIP_DEFLATED) as zout:
            for item in zin.infolist():
                data = xml.encode("utf-8") if item.filename == "word/document.xml" else zin.read(item.filename)
                zout.writestr(item, data)
    return counts


def progress_table(task_list):
    rows = ["| الدفعة | خلص | الكل |", "|---|---|---|"]
    total_done = total = 0
    for key, title in BATCHES.items():
        batch = [t for t in task_list if t[1] == key]
        if not batch:
            continue
        d = sum(1 for t in batch if t[2])
        total_done += d
        total += len(batch)
        rows.append(f"| {title} | {d} | {len(batch)} |")
    rows.append(f"| **الإجمالي** | **{total_done}** | **{total}** |")
    return "\n".join(rows)


def write_progress(task_list):
    text = TASKS.read_text(encoding="utf-8")
    block = f"<!-- progress:start -->\n{progress_table(task_list)}\n<!-- progress:end -->"
    if "<!-- progress:start -->" in text:
        text = re.sub(r"<!-- progress:start -->.*?<!-- progress:end -->", lambda _: block, text, flags=re.S)
    else:
        marker = "## الملخص"
        text = text.replace(marker, f"## التقدم (يتحدّث تلقائياً من make_status.py)\n\n{block}\n\n{marker}", 1)
    TASKS.write_text(text, encoding="utf-8")


def main():
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8")
    task_list = tasks()
    done, planned = classify(task_list)
    counts = build(done, planned)
    write_progress(task_list)
    ticked = sum(1 for t in task_list if t[2])
    print(f"Tasks: {ticked}/{len(task_list)} done · items green {counts['green']}, red {counts['red']}, grey {counts['grey']}")
    print(f"Wrote {TARGET.name}")


if __name__ == "__main__":
    main()
