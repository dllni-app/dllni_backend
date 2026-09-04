from pathlib import Path

path = Path("Modules/Cleaning/app/Models/CleaningBookingSession.php")
text = path.read_text()
marker = "final class CleaningBookingSession extends Model\n{\n"
replacement = "final class CleaningBookingSession extends Model\n{\n    public const TYPE_RECURRING_CLEANING = 'recurring_cleaning';\n\n"
count = text.count(marker)
if count != 1:
    raise SystemExit(f"CleaningBookingSession class marker: expected 1, found {count}")
path.write_text(text.replace(marker, replacement, 1))
