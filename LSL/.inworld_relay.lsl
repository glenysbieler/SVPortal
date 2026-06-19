// inworld_relay.lsl
//
// Sub-Version Space Portal — In-World Messaging Relay
// =====================================================
//
// Purpose
// -------
// This script lets the web portal deliver instant messages to residents
// through OpenSim's normal messaging system (llInstantMessage), so online
// users receive messages immediately and offline users receive them on
// next login - exactly like a real resident-to-resident IM.
//
// Place this script in a single prim, rename the object to "SYSTEM" (or
// whatever name you want messages to appear "from"), and put it somewhere
// secure and permanent - a sim's server room, your estate owner's house,
// etc. It should NOT be moved between regions once set up, because its
// HTTPIN URL is tied to the region it's rezzed in.
//
// Setup
// -----
// 1. Create a notecard inside this object named exactly: access_code
//    Its contents must be a single line containing the SAME value as
//    INWORLD_RELAY_ACCESS_CODE in the portal's config.php. e.g.:
//
//        ChangeMeN0W+$
//
// 2. Rez the object. On startup it will:
//      - read the access code from the notecard
//      - request an HTTPIN URL from the simulator
//      - POST a check-in to the portal's inworld_checkin.php with its
//        URL, object UUID, region name, and access code
//
// 3. In config.php, set:
//      define('INWORLD_MESSAGING', 'inworldobject');
//
// Owner menu
// ----------
// Touch the object (owner only) to open a small menu:
//   HTTPIN   - llOwnerSay()s the current HTTPIN URL (useful for debugging)
//   Checkin  - re-sends the check-in to the portal immediately
//
// Notes
// -----
// - The HTTPIN URL changes if the region restarts. This script
//   re-requests a URL and re-checks-in automatically on every
//   state_entry() (i.e. on rez and on every region restart), so no
//   manual action is normally needed.
// - The portal ALWAYS writes the message to the im_offline table first,
//   guaranteeing delivery on next login regardless of this object's status.
//   The message sent to this object is purely a "heads up" for users who
//   are currently online - if this object is offline, unreachable, or has
//   never checked in, the portal simply skips the heads-up and the
//   im_offline delivery still happens normally. This object is a "nice to
//   have", not a single point of failure.
// - Two payload formats are used, matching what's simplest on each side:
//     - Check-in (object -> portal): urlencoded form fields, since PHP's
//       $_POST handles these with zero extra parsing.
//     - Relay request (portal -> object): JSON body, parsed here with
//       llJsonGetValue() - this is the convention used for portal -> object
//       calls on other Sub-Version Space integrations.
 
// ─── Configuration ──────────────────────────────────────────────────────────
 
string CHECKIN_URL = "https://portal.sub-version.space/inworld_checkin.php";
 
// ─── State ──────────────────────────────────────────────────────────────────
 
string  gAccessCode;
string  gHttpinUrl;
key     gUrlRequestId;
key     gCheckinRequestId;
integer gDialogChannel;
 
// ─── Helpers ────────────────────────────────────────────────────────────────
 
ReadAccessCode()
{
    gAccessCode = "";
 
    if (llGetInventoryType("access_code") != INVENTORY_NOTECARD)
    {
        llOwnerSay("WARNING: 'access_code' notecard not found. This object cannot " +
                   "check in or relay messages until it is added.");
        return;
    }
 
    // Read line 0 only - the access code should be a single line.
    llGetNotecardLine("access_code", 0);
}
 
DoCheckin()
{
    if (gAccessCode == "")
    {
        // Try reading the notecard again in case it was added after rez
        ReadAccessCode();
        return;
    }
 
    if (gHttpinUrl == "")
    {
        llOwnerSay("Cannot check in yet - no HTTPIN URL. Requesting one now.");
        gUrlRequestId = llRequestURL();
        return;
    }
 
    // Send as urlencoded form fields so the PHP side can use $_POST directly.
    string form = "access_code=" + llEscapeURL(gAccessCode)
                 + "&httpin_url=" + llEscapeURL(gHttpinUrl)
                 + "&object_uuid=" + llEscapeURL((string)llGetKey())
                 + "&region_name=" + llEscapeURL(llGetRegionName());
 
    list params = [
        HTTP_METHOD, "POST",
        HTTP_MIMETYPE, "application/x-www-form-urlencoded",
        HTTP_BODY_MAXLENGTH, 4096
    ];
 
    gCheckinRequestId = llHTTPRequest(CHECKIN_URL, params, form);
}
 
// ─── Events ─────────────────────────────────────────────────────────────────
 
default
{
    state_entry()
    {
        llSetObjectName("SYSTEM");
        gHttpinUrl = "";
        gDialogChannel = -1 - (integer)("0x" + llGetSubString((string)llGetKey(), 0, 6));
        llListen(gDialogChannel, "", llGetOwner(), "");
        ReadAccessCode();
        gUrlRequestId = llRequestURL();
    }
 
    on_rez(integer start_param)
    {
        llResetScript();
    }
 
    changed(integer change)
    {
        if (change & CHANGED_REGION_START)
        {
            // Region restarted - our old HTTPIN URL is dead, get a new one
            // and re-check-in automatically.
            llResetScript();
        }
    }
 
    http_request(key id, string method, string body)
    {
        if (id == gUrlRequestId)
        {
            if (method == URL_REQUEST_GRANTED)
            {
                gHttpinUrl = body;
                llOwnerSay("In-world relay ready. HTTPIN URL: " + gHttpinUrl);
                DoCheckin();
            }
            else if (method == URL_REQUEST_DENIED)
            {
                llOwnerSay("URL request denied: " + body +
                           ". This region may not support llRequestURL() " +
                           "(check that the [LL-Functions] / HTTP server " +
                           "settings are enabled).");
            }
            return;
        }
 
        // ─── Incoming relay request from the portal ───────────────────────
        if (method == "POST")
        {
            // Expect JSON: {"access_code":"...","to_uuid":"...","message":"..."}
            string code    = llJsonGetValue(body, ["access_code"]);
            string toUuid  = llJsonGetValue(body, ["to_uuid"]);
            string message = llJsonGetValue(body, ["message"]);
 
            if (code == JSON_INVALID || toUuid == JSON_INVALID || message == JSON_INVALID)
            {
                llHTTPResponse(id, 400, "FAIL: malformed JSON");
                return;
            }
 
            if (gAccessCode == "" || code != gAccessCode)
            {
                llHTTPResponse(id, 403, "FAIL: access code mismatch");
                return;
            }
 
            if (llGetKey() == NULL_KEY)
            {
                llHTTPResponse(id, 500, "FAIL: invalid object key");
                return;
            }
 
            // Deliver the IM. Online users get it immediately; offline users
            // get it via the normal im_offline queue on next login.
            llInstantMessage((key)toUuid, message);
 
            llHTTPResponse(id, 200, "OK");
        }
        else
        {
            llHTTPResponse(id, 405, "FAIL: POST required");
        }
    }
 
    dataserver(key query_id, string data)
    {
        // Notecard line 0 read result
        if (data == EOF)
        {
            llOwnerSay("WARNING: 'access_code' notecard is empty.");
            return;
        }
 
        gAccessCode = llStringTrim(data, STRING_TRIM);
 
        if (gHttpinUrl != "")
        {
            DoCheckin();
        }
    }
 
    touch_start(integer total_number)
    {
        if (llDetectedKey(0) != llGetOwner())
        {
            return;
        }
 
        llDialog(llGetOwner(),
            "Sub-Version Portal Relay\n\n" +
            "Region: " + llGetRegionName() + "\n" +
            "HTTPIN: " + gHttpinUrl,
            ["HTTPIN", "Checkin"], gDialogChannel);
    }
 
    listen(integer channel, string name, key id, string message)
    {
        if (message == "HTTPIN")
        {
            if (gHttpinUrl == "")
            {
                llOwnerSay("No HTTPIN URL yet - requesting one now.");
                gUrlRequestId = llRequestURL();
            }
            else
            {
                llOwnerSay("Current HTTPIN URL: " + gHttpinUrl);
            }
        }
        else if (message == "Checkin")
        {
            llOwnerSay("Checking in with the portal...");
            DoCheckin();
        }
    }
 
    http_response(key request_id, integer status, list metadata, string body)
    {
        if (request_id == gCheckinRequestId)
        {
            if (status == 200 && llSubStringIndex(body, "OK") == 0)
            {
                llOwnerSay("Check-in successful.");
            }
            else
            {
                llOwnerSay("Check-in failed (HTTP " + (string)status + "): " + body);
            }
        }
    }
}
