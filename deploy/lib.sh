# Shared helpers for deploy/install.sh and deploy/install-offhost.sh (sourced, not executed).
# shellcheck shell=bash

# Toolchains (owner decision: official downloads, checksum-pinned; verified when pinned):
# - Go: the official toolchain module golang.org/toolchain (the channel `go` itself uses for
#   GOTOOLCHAIN) from proxy.golang.org; the SHA-256 below is of the module zip whose h1: hash
#   matched Go's public checksum database (sum.golang.org). go1.26.8 = supported release line.
# - Node: nodejs.org release tarball; the SHA-256 is from SHASUMS256.txt, whose signature was
#   verified with the Node release key 5BE8A3F6C8A5C01D106C0AD820B1A390B168D356.
GO_VERSION=1.26.8
GO_URL="https://proxy.golang.org/golang.org/toolchain/@v/v0.0.1-go${GO_VERSION}.linux-amd64.zip"
GO_SHA256=30c2b1bf7dcc88d3eb0a1364e47ddd9128edb3110a30e8a0ef61cd5856b31de7
NODE_VERSION=22.23.3
NODE_URL="https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-x64.tar.xz"
NODE_SHA256=df450af89261115ef9f9e3830c3eeb2cc9213b63c720b1af623cb5dcbe2e02de

log()  { printf '\033[1m==> %s\033[0m\n' "$*" >&2; }
info() { printf '    %s\n' "$*" >&2; }
warn() { printf '\033[33mWARNING: %s\033[0m\n' "$*" >&2; WARNINGS+=("$*"); }
die()  { printf '\033[31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }
WARNINGS=()

require_root() { [[ $EUID -eq 0 ]] || die "run as root"; }

require_ubuntu_2404() {
  # shellcheck disable=SC1091
  . /etc/os-release
  [[ "${ID:-}" == ubuntu && "${VERSION_ID:-}" == 24.04 ]] || die "Ubuntu 24.04 LTS required (found ${PRETTY_NAME:-unknown})"
  [[ "$(uname -m)" == x86_64 ]] || die "x86_64 required (pinned toolchains are linux-amd64)"
}

# read_conf FILE — KEY=VALUE lines into CONF[...] without executing the file
declare -A CONF
read_conf() {
  local file=$1 line key val
  [[ -r "$file" ]] || die "configuration file $file not readable"
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line#"${line%%[![:space:]]*}"}"
    [[ -z "$line" || "$line" == \#* ]] && continue
    [[ "$line" == *=* ]] || die "$file: not KEY=VALUE: $line"
    key="${line%%=*}"; val="${line#*=}"
    [[ "$key" =~ ^[A-Z][A-Z0-9_]*$ ]] || die "$file: invalid key $key"
    if [[ ${#val} -ge 2 && ( "$val" == \"*\" || "$val" == \'*\' ) ]]; then val="${val:1:${#val}-2}"; fi
    CONF[$key]=$val
  done < "$file"
}
conf()     { printf '%s' "${CONF[$1]:-${2:-}}"; }
need_conf() { [[ -n "${CONF[$1]:-}" ]] || die "configuration value $1 is required"; }

rand_hex() { od -An -N"${1:-32}" -tx1 /dev/urandom | tr -d ' \n'; }

# env_get FILE KEY / env_set FILE KEY VALUE — single-line KEY=VALUE files (app .env format:
# raw values, no escapes), updated in place without touching other lines
env_get() { sed -n "s/^$2=//p" "$1" 2>/dev/null | tail -n1; }
env_set() {
  local file=$1 key=$2 val=$3
  [[ "$val" != *$'\n'* ]] || die "value for $key contains a newline"
  if grep -q "^$key=" "$file"; then
    local tmp; tmp=$(mktemp)
    awk -v k="$key" -v v="$val" 'BEGIN{FS=OFS="="} $1==k {print k "=" v; next} {print}' "$file" > "$tmp"
    cat "$tmp" > "$file"; rm -f "$tmp"
  else
    printf '%s=%s\n' "$key" "$val" >> "$file"
  fi
}
# env_secret FILE KEY — keep an existing value, otherwise generate one (never rotated here)
env_secret() {
  local cur; cur=$(env_get "$1" "$2")
  if [[ -z "$cur" ]]; then env_set "$1" "$2" "$(rand_hex 32)"; fi
}

ensure_group() { getent group "$1" > /dev/null || groupadd --system "$1"; }
# ensure_user NAME HOME SHELL [CREATE_HOME:yes|no]
ensure_user() {
  local name=$1 home=$2 shell=$3 create=${4:-no}
  if ! id "$name" > /dev/null 2>&1; then
    local args=(--system --home-dir "$home" --shell "$shell")
    if getent group "$name" > /dev/null; then args+=(--gid "$name"); else args+=(--user-group); fi
    [[ "$create" == yes ]] && args+=(--create-home) || args+=(--no-create-home)
    useradd "${args[@]}" "$name"
  fi
}
add_to_group() { id -nG "$1" | tr ' ' '\n' | grep -qx "$2" || usermod -aG "$2" "$1"; }

# fetch_verified URL SHA256 DEST
fetch_verified() {
  local url=$1 sum=$2 dest=$3
  curl -fsSL --proto '=https' --tlsv1.2 -o "$dest.part" "$url" || die "download failed: $url"
  echo "$sum  $dest.part" | sha256sum -c --quiet - || { rm -f "$dest.part"; die "checksum mismatch for $url"; }
  mv "$dest.part" "$dest"
}

install_go() {
  local dir=/opt/go-$GO_VERSION
  if [[ ! -x "$dir/bin/go" ]]; then
    log "Installing Go $GO_VERSION (checksum-pinned toolchain module)"
    local tmp; tmp=$(mktemp -d)
    fetch_verified "$GO_URL" "$GO_SHA256" "$tmp/go.zip"
    unzip -q "$tmp/go.zip" -d "$tmp/x"
    mv "$tmp/x/golang.org/toolchain@v0.0.1-go${GO_VERSION}.linux-amd64" "$dir"
    # module zips carry no Unix modes: restore the executables like `go` does for toolchains
    chmod 0755 "$dir"/bin/* "$dir"/pkg/tool/linux_amd64/*
    rm -rf "$tmp"
  fi
  "$dir/bin/go" version | grep -q "go$GO_VERSION " || die "Go installation in $dir is not $GO_VERSION"
  # shellcheck disable=SC2034  # used by the scripts that source this file
  GO="$dir/bin/go"
}

install_node() {
  local dir=/opt/node-v$NODE_VERSION
  if [[ ! -x "$dir/bin/node" ]]; then
    log "Installing Node $NODE_VERSION (checksum-pinned release tarball)"
    local tmp; tmp=$(mktemp -d)
    fetch_verified "$NODE_URL" "$NODE_SHA256" "$tmp/node.tar.xz"
    mkdir -p "$dir"
    tar -xJf "$tmp/node.tar.xz" -C "$dir" --strip-components=1 --no-same-owner
    rm -rf "$tmp"
  fi
  [[ "$("$dir/bin/node" --version)" == "v$NODE_VERSION" ]] || die "Node installation in $dir is not $NODE_VERSION"
  ln -sfn "$dir/bin/node" /usr/local/bin/node
  # shellcheck disable=SC2034  # used by the scripts that source this file
  NODE_BIN="$dir/bin"
}

apt_install() {
  export DEBIAN_FRONTEND=noninteractive
  local missing=()
  for p in "$@"; do dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q 'install ok installed' || missing+=("$p"); done
  if (( ${#missing[@]} )); then
    log "Installing packages: ${missing[*]}"
    apt-get update -qq
    apt-get install -y -qq --no-install-recommends "${missing[@]}" > /dev/null
  fi
}

# sql_root: statements on stdin, as MariaDB root via the unix socket (no password on argv)
sql_root() { mysql --batch --skip-column-names; }
sql_quote() { printf "%s" "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/''/g"; }

print_warnings() {
  if (( ${#WARNINGS[@]} )); then
    printf '\n\033[33mOpen items (%d):\033[0m\n' "${#WARNINGS[@]}" >&2
    for w in "${WARNINGS[@]}"; do printf '  - %s\n' "$w" >&2; done
  fi
}
