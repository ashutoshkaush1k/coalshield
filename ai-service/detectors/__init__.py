"""The anomaly detectors (Phase 7): stateless functions payload -> flags.

Each flag: {detector, mine_id, subject, from, to, score, reasons: [{code, params}], entities}.
The API builds the payloads from its database and stores the flags; nothing here touches data
or users. The same algorithms run in PHP (api/services/detectors/) when this service is down.
"""

from . import contractor, flatline, grievance_cluster, late_actions, night_shift, production, repeat

VERSION = "1.0.0"
DETECTORS = {m.NAME: m.detect for m in (production, flatline, night_shift, repeat, late_actions, contractor, grievance_cluster)}
