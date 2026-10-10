# 10 Reviewing contributions from website visitors

Websites with ***community features*** let visitors create content themselves: they register, maintain
their profile and write comments, reviews or classified ads. The role is not fixed – these may be forum
members, customers, employees or similar. In camindo CMS they are collectively called
***frontend users***.

## Where the content of frontend users is stored

Frontend users are ordinary records in so-called profile areas.

- ***Profile areas*** (symbol ![face](../_/face.svg)) contain one record per frontend user with email
  address, login name, password and further profile data.
- ***Contribution areas*** (symbol ![chat_bubble](../_/chat_bubble.svg)) contain what frontend users
  write: comments, classified ads, pictures.

You edit, search and export these records like all others. The detail view shows which frontend user
a contribution belongs to.

## Reviewing content

Everything a frontend user creates or changes is marked with ***“Needs review”*** (symbol
![emergency](../_/emergency.svg)), provided your template programmer has set this up. This way you keep
track of what still needs to be checked:

- The widget ***“Unreviewed content of frontend users”*** on the start page lists all marked records
  (set it up in your [personal settings](./03-work-surface.md#start-page-widgets)).
- In the record list, the menu of the status column with “Unreviewed” shows only the marked records.

Once you have reviewed a contribution, switch off “Needs review” in the input form. If a contribution is
not acceptable, deactivate it via the status or delete it.

## Exporting personal data

If a website user wants to know what data is stored about them (Article 15 GDPR), or asks for a copy to
take with them (Article 20 GDPR), create a ***personal data export***:

1. Open the profile record in the detail view and choose ![more_vert](../_/more_vert.svg)
   ***“Export of personal data”*** in the menu on the right – or open the module “Privacy”
   ![shield](../_/shield.svg) → “Data export”, search for the profile with ![search](../_/search.svg) and
   tick it in the list. A profile that is already in the trash can be selected from the list “or a profile
   in the trash” below.
2. Check the person. If the instance has templates in several languages, choose the ***language of the
   report***. If no template exists, no report can be created yet – contact your administrator.
3. Read the warning: the report may only be created by an authorized person (usually the data protection
   officer), and the user learns of it in every later report. Tick ***“I am authorized to create the
   personal data report.”*** and click ***“Create personal data report”***.
4. Download the zip file with ***“Download zip file”*** – the link works exactly once and for at most one day.

The zip file contains `report.html` (readable, for printing or sending), `data.json` (the same data in
machine-readable form) and the folder `files/` with the uploaded files. It includes all fields of the
profile, the contributions of the user by area, permanently deleted contributions that the change log still
knows, records that refer to their profile (such as orders), devices and sessions with their retention
period, failed sign-in attempts, the change log, the retention periods and what happens when the profile is
deleted. Names of other persons appear in it only as “Admin” or “User”.

Good to know:

- The export is a ***partial extract***. Complete it before handing it over – for example with the legal
  bases, server log files and data from other systems (accounting, email). If in doubt, ask your data
  protection officer.
- The report is created in the language of the chosen template – regardless of your backend language. So
  choose the user’s language if there is a template for it.
- The zip file contains personal data. Pass it on only by a secure route – not as a simple email attachment
  to an address whose owner you have not verified.
- Reply within one month. Every export is recorded in the audit log.
- You only see this menu item with access to the module “Privacy” and the permission “Export personal data”.
  If you lack it, contact your administrator.

## Deleting a profile

As with every record, the deletion policy of the profile area determines what happens when a profile is
deleted; the confirmation prompt shows this beforehand – including what happens to the contributions of the
user. With the standard policy, the profile and contributions go to the trash and are permanently deleted
after 7 days; known devices and sessions of the user are removed immediately. If the user requests immediate
deletion, an administrator with the matching permission can empty their trash straight away.

## Registration and status

After registration, a profile initially has the status “Unconfirmed”, until the frontend user clicks the link
in their confirmation email. Only then can they sign in. You can also activate or lock a profile by hand via
its status.
