"""Fleet-wide current sensor standing (Government risk view).

The distinction being tested is between "breaching right now" and "has breached before" -
the risk view exists precisely because the Overview board only shows the latter.
"""

from app.services.iot.fleet_status import fleet_sensor_standing
from app.services.iot.thresholds import SensorType


def add_reading(db, mine_id, sensor: SensorType, value, breached, resolved=False):
    from app.models.sensor_reading import SensorReading

    row = SensorReading(mine_id=mine_id, sensor_type=sensor.value, value=value,
                        unit=sensor.unit, breached=breached, resolved=resolved)
    db.add(row)
    db.flush()
    return row


class TestAccess:
    def test_government_can_read_the_fleet_view(self, api, gov, accounts):
        r = api.get("/api/v1/sensors", headers=gov)
        assert r.status_code == 200
        assert r.json()["mine_count"] >= 1

    def test_mine_head_gets_403(self, api, head):
        """Same pattern as /inspections: a cross-mine comparison has no meaning for one
        operator and would leak other mines' conditions."""
        assert api.get("/api/v1/sensors", headers=head).status_code == 403

    def test_unauthenticated_gets_401(self, api, accounts):
        assert api.get("/api/v1/sensors").status_code == 401

    def test_mine_head_can_still_read_their_own_mine(self, api, head, accounts):
        assert api.get(f"/api/v1/sensors/{accounts['mine'].id}", headers=head).status_code == 200


class TestStanding:
    def test_latest_reading_wins(self, db, make_mine):
        mine = make_mine()
        add_reading(db, mine.id, SensorType.GAS, 80.0, True)
        add_reading(db, mine.id, SensorType.GAS, 12.0, False)     # newer, compliant

        gas = next(s for s in fleet_sensor_standing(db)[0].sensors if s.sensor_type == "gas")
        assert gas.value == 12.0
        assert gas.breached is False
        assert gas.status_label == "Within safe range"

    def test_history_is_reported_separately_from_current_state(self, db, make_mine):
        """A mine can be clean right now and still carry a bad history - the whole
        reason this view is distinct from the Overview scores."""
        mine = make_mine()
        for _ in range(4):
            add_reading(db, mine.id, SensorType.GAS, 80.0, True)
        add_reading(db, mine.id, SensorType.GAS, 10.0, False)

        standing = fleet_sensor_standing(db)[0]
        assert standing.breaching_now == []
        assert standing.total_open_breaches == 4

    def test_plain_language_states(self, db, make_mine):
        mine = make_mine()
        add_reading(db, mine.id, SensorType.GAS, 80.0, True)
        add_reading(db, mine.id, SensorType.DUST, 9.6, False)      # within 10% of 10.0
        add_reading(db, mine.id, SensorType.TEMPERATURE, 20.0, False)

        labels = {s.sensor_type: s.status_label for s in fleet_sensor_standing(db)[0].sensors}
        assert labels["gas"] == "Breached"
        assert labels["dust"] == "Approaching limit"
        assert labels["temperature"] == "Within safe range"

    def test_severity_scales_with_how_far_past_the_limit(self, db, make_mine):
        mine = make_mine()
        add_reading(db, mine.id, SensorType.GAS, 51.0, True)       # just over 50
        marginal = next(s for s in fleet_sensor_standing(db)[0].sensors if s.sensor_type == "gas")
        assert marginal.severity == "LOW"

        add_reading(db, mine.id, SensorType.GAS, 90.0, True)       # 80% over
        severe = next(s for s in fleet_sensor_standing(db)[0].sensors if s.sensor_type == "gas")
        assert severe.severity == "HIGH"

    def test_breaching_mines_sort_first(self, db, make_mine):
        calm, hot = make_mine(), make_mine()
        add_reading(db, calm.id, SensorType.GAS, 10.0, False)
        add_reading(db, hot.id, SensorType.GAS, 90.0, True)

        assert fleet_sensor_standing(db)[0].mine_id == hot.id

    def test_breach_counts_are_categorised_by_sensor(self, db, make_mine):
        """Backs the "this mine's problem is mostly gas, not dust" breakdown."""
        mine = make_mine()
        for _ in range(3):
            add_reading(db, mine.id, SensorType.GAS, 80.0, True)
        add_reading(db, mine.id, SensorType.DUST, 15.0, True)

        by_type = {s.sensor_type: s.open_breaches for s in fleet_sensor_standing(db)[0].sensors}
        assert by_type["gas"] == 3
        assert by_type["dust"] == 1
        assert by_type["temperature"] == 0

    def test_resolved_breaches_do_not_count(self, db, make_mine):
        mine = make_mine()
        add_reading(db, mine.id, SensorType.GAS, 80.0, True, resolved=True)
        assert fleet_sensor_standing(db)[0].total_open_breaches == 0

    def test_mine_with_no_readings_reports_cleanly(self, db, make_mine):
        make_mine()
        standing = fleet_sensor_standing(db)[0]
        assert [s.value for s in standing.sensors] == [None, None, None]
        assert standing.worst_severity == "OK"
        assert all(s.status_label == "No readings yet" for s in standing.sensors)
