// Makes the certificates for the field server's HTTPS (Phase 7B). No administrator rights and
// nothing installed: it uses mkcert when it is on the PATH, otherwise the OpenSSL that comes with
// Git for Windows.
//
//   certs/ca.crt, ca.key        a local certificate authority for this PC only (10 years). ca.crt is
//                               what a phone installs, once; ca.key never leaves this PC.
//   certs/server.crt, .key      the server's certificate, signed by that CA, for localhost, this
//                               PC's name and its current LAN addresses (397 days)
//
//   node scripts/make_cert.mjs            make the CA if missing, then a fresh server certificate
//   node scripts/make_cert.mjs --new-ca   start over with a new CA (phones must install it again)
//
// Run it again when the PC's LAN address changes: the CA stays, so phones need nothing new.
// certs/ is git-ignored. Nothing here is trusted by any device until someone installs ca.crt.
import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
import { hostname, networkInterfaces } from "node:os";
import { join, resolve } from "node:path";

const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..");
const CERTS = join(ROOT, "certs");
mkdirSync(CERTS, { recursive: true });

const lan = Object.values(networkInterfaces()).flat().filter((a) => a && a.family === "IPv4" && !a.internal).map((a) => a.address);
const host = hostname().toLowerCase();
const names = ["localhost", host, `${host}.local`];
const ips = ["127.0.0.1", ...lan];

function find(cmd, candidates) {
  try {
    execFileSync(cmd, ["version"], { stdio: "ignore" });
    return cmd;
  } catch {
    return candidates.find(existsSync) ?? null;
  }
}

const mkcert = find("mkcert", []);
if (mkcert && !process.argv.includes("--openssl")) {
  // mkcert keeps its CA in its own folder (mkcert -CAROOT); copy the public part next to ours.
  execFileSync(mkcert, ["-cert-file", join(CERTS, "server.crt"), "-key-file", join(CERTS, "server.key"), ...names, ...ips], { stdio: "inherit" });
  const caroot = execFileSync(mkcert, ["-CAROOT"]).toString().trim();
  writeFileSync(join(CERTS, "ca.crt"), execFileSync(process.execPath, ["-e", `process.stdout.write(require('fs').readFileSync(${JSON.stringify(join(caroot, "rootCA.pem"))}))`]));
  console.log(`mkcert: certs/server.crt for ${[...names, ...ips].join(", ")}; CA copied to certs/ca.crt`);
  process.exit(0);
}

const openssl = find("openssl", ["C:/Program Files/Git/usr/bin/openssl.exe", "C:/Program Files/Git/mingw64/bin/openssl.exe"]);
if (!openssl) {
  console.error("Neither mkcert nor openssl found. Install Git for Windows (it includes openssl) or put mkcert on the PATH.");
  process.exit(1);
}
const run = (args) => execFileSync(openssl, args, { stdio: ["ignore", "ignore", "inherit"] });
const f = (name) => join(CERTS, name);

if (process.argv.includes("--new-ca")) for (const n of ["ca.key", "ca.crt", "ca.srl"]) rmSync(f(n), { force: true });
if (!existsSync(f("ca.key")) || !existsSync(f("ca.crt"))) {
  writeFileSync(f("ca.cnf"), [
    "[req]", "distinguished_name = dn", "prompt = no", "x509_extensions = ext",
    "[dn]", `CN = Smart Mine Governance local CA (${host})`, "O = Smart Mine Governance (development)",
    "[ext]", "basicConstraints = critical, CA:TRUE, pathlen:0", "keyUsage = critical, keyCertSign, cRLSign",
    "subjectKeyIdentifier = hash", "",
  ].join("\n"));
  run(["req", "-quiet", "-x509", "-newkey", "rsa:2048", "-nodes", "-sha256", "-days", "3650", "-keyout", f("ca.key"), "-out", f("ca.crt"), "-config", f("ca.cnf")]);
  rmSync(f("ca.cnf"));
  console.log("new local CA: certs/ca.crt (install this on phones), certs/ca.key (keep on this PC)");
}

const san = [...names.map((n, i) => `DNS.${i + 1} = ${n}`), ...ips.map((ip, i) => `IP.${i + 1} = ${ip}`)];
writeFileSync(f("server.cnf"), [
  "[req]", "distinguished_name = dn", "prompt = no", "[dn]", `CN = ${host}`,
  "[ext]", "basicConstraints = CA:FALSE", "keyUsage = critical, digitalSignature, keyEncipherment",
  "extendedKeyUsage = serverAuth", "subjectAltName = @san", "authorityKeyIdentifier = keyid",
  "[san]", ...san, "",
].join("\n"));
run(["req", "-quiet", "-newkey", "rsa:2048", "-nodes", "-sha256", "-keyout", f("server.key"), "-out", f("server.csr"), "-config", f("server.cnf")]);
run(["x509", "-req", "-in", f("server.csr"), "-CA", f("ca.crt"), "-CAkey", f("ca.key"), "-CAcreateserial", "-days", "397", "-sha256",
  "-extfile", f("server.cnf"), "-extensions", "ext", "-out", f("server.crt")]);
rmSync(f("server.csr"));
rmSync(f("server.cnf"));
console.log(`server certificate: certs/server.crt for ${[...names, ...ips].join(", ")} (397 days)`);
