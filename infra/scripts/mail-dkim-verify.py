"""Verify the DKIM signature of a raw message without public DNS (docs/09-infrastructure/docker.md §Mail).

    uvx --from dkimpy==1.1.8 python infra/scripts/mail-dkim-verify.py message.eml <selector> <base64 public key>

The selector and key are the ones mail-init.sh prints (MAIL_DKIM_SELECTOR, MAIL_DKIM_PUBLIC_KEY). The DNS
lookup of `<selector>._domainkey.<domain>` is answered with that key, so a development domain such as
shp.localhost can be checked. Exit code 0 when the signature verifies.
"""

import sys

import dkim


def main() -> int:
    if len(sys.argv) != 4:
        print(__doc__)
        return 2
    path, selector, public_key = sys.argv[1:]
    with open(path, "rb") as handle:
        message = handle.read()

    def dns(name: bytes, timeout: int = 5) -> bytes:
        if name.decode().startswith(f"{selector}._domainkey."):
            return f"v=DKIM1; k=rsa; p={public_key}".encode()
        return b""

    verifier = dkim.DKIM(message)
    try:
        ok = verifier.verify(dnsfunc=dns)
    except dkim.DKIMException as error:
        print(f"DKIM fail: {error}")
        return 1
    domain = verifier.domain.decode() if verifier.domain else "?"
    print(f"DKIM {'pass' if ok else 'fail'}: d={domain} s={selector}")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
