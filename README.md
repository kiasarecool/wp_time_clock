# Employee Time Clock

A self-contained WordPress employee time clock built for a shared kiosk/tablet workflow, with management review and payroll-friendly reporting.

## Features

- Shared kiosk clock in/out page
- Employee selection with 4-digit PIN verification
- Server-authoritative timestamps
- Current clock state and last punch display
- Employee-submitted punch corrections for management review
- Missing punch and missing shift tools for management
- Exception review with Accept, Edit, Details, and Ignore actions
- Reports locked until required reviews are resolved
- All-employee and individual employee reporting
- Daily hour breakdowns and employee totals
- CSV export
- Multi-sheet XLSX employee time-card export
- Audit history for corrections and management actions
- WordPress user-profile controls for employees and time-record access
- Hidden/system users excluded from normal kiosk and payroll reporting
- Kiosk self-refresh to recover from stale overnight browser sessions
- Built-in WordPress admin instructions

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

1. Download or clone this repository.
2. Put the `pf-employee-time-clock` plugin folder in `wp-content/plugins/`, or ZIP the folder and upload it through **Plugins → Add New → Upload Plugin**.
3. Activate **PF Employee Time Clock**.
4. Configure employee users from their WordPress user profiles, including a 4-digit PIN.
5. Create a kiosk page containing:

   `[pf_time_clock]`

6. Create a management/report page containing:

   `[pf_time_clock_admin]`

7. Grant time-record access only to the users who should manage reports and corrections.

The plugin also adds a **Time Clock** section to the WordPress admin menu with instructions, report access, and management correction tools.

## Version

Current public release: **1.1.10**

## Notes

This plugin was originally built for The Plant Factory's internal employee timekeeping workflow and is now being made public. Test it with your WordPress setup and payroll process before relying on it for production timekeeping.

## Author

The Plant Factory

**–by KiasAreCool**
