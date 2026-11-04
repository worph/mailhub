"""Spike: drop a plain mail and an iMIP invitation into the fake provider inboxes."""
import smtplib, uuid, datetime as dt
from email.message import EmailMessage

HOST, PORT = "mailhub-fakeprovider", 3025
start = (dt.datetime.utcnow() + dt.timedelta(days=2)).replace(hour=14, minute=0, second=0, microsecond=0)
fmt = lambda d: d.strftime("%Y%m%dT%H%M%SZ")

plain = EmailMessage()
plain["From"], plain["To"], plain["Subject"] = "carol@other.test", "bob@namecheap.test", "Hello Namecheap identity"
plain.set_content("Plain test mail to the Namecheap stand-in.")

uid = f"{uuid.uuid4()}@other.test"
ics = f"""BEGIN:VCALENDAR
PRODID:-//MailHub spike//EN
VERSION:2.0
METHOD:REQUEST
BEGIN:VEVENT
UID:{uid}
DTSTAMP:{fmt(dt.datetime.utcnow())}
DTSTART:{fmt(start)}
DTEND:{fmt(start + dt.timedelta(hours=1))}
SUMMARY:MailHub spike meeting
ORGANIZER;CN=Olivia Organizer:mailto:olivia@other.test
ATTENDEE;CN=Alice;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:alice@gmail.test
SEQUENCE:0
STATUS:CONFIRMED
END:VEVENT
END:VCALENDAR
""".replace("\n", "\r\n")
inv = EmailMessage()
inv["From"], inv["To"], inv["Subject"] = "Olivia Organizer <olivia@other.test>", "alice@gmail.test", "Invitation: MailHub spike meeting"
inv.set_content("You are invited to the MailHub spike meeting.")
inv.add_alternative(ics, subtype="calendar", params={"method": "REQUEST"})

with smtplib.SMTP(HOST, PORT) as s:
    s.send_message(plain)
    s.send_message(inv)
print("sent plain mail to bob@namecheap.test and invitation", uid, "to alice@gmail.test")
