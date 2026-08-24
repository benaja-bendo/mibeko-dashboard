#!/usr/bin/env python3
"""Applicateur Classe 2 pour mibeko-dashboard#56.

Simulation par défaut. Chaque sous-opération d'écriture exige ``--execute``,
un préflight lecture seule concluant, un dump frais et une confirmation
interactive. Le script ne supprime physiquement aucune ligne et ne touche pas
à MinIO. La republication n'est volontairement pas automatisée : elle reste
une décision éditoriale ultérieure.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path
from typing import Any


SCRIPT_PATH = Path(__file__).resolve()
PLAN_PATH = SCRIPT_PATH.with_suffix(".json")
REPO_ROOT = SCRIPT_PATH.parents[3]
API_BASE = os.getenv("MIBEKO_API_BASE", "https://api.mibeko.fr/api/v1").rstrip("/")
OPERATIONS = ("retirer", "reconstruire", "corriger-autorite", "rollback-extraction")


class OperationError(RuntimeError):
    pass


def load_plan() -> dict[str, Any]:
    with PLAN_PATH.open(encoding="utf-8") as stream:
        return json.load(stream)


class Api:
    def __init__(self, token: str) -> None:
        self.token = token

    def data(
        self,
        method: str,
        path: str,
        payload: dict[str, Any] | None = None,
        query: dict[str, str] | None = None,
    ) -> Any:
        url = f"{API_BASE}{path}"
        if query:
            url = f"{url}?{urllib.parse.urlencode(query)}"
        body = None
        headers = {
            "Accept": "application/json",
            "Authorization": f"Bearer {self.token}",
            "User-Agent": "MibekoProductionCorrection/2026-08-18-issue-56",
        }
        if payload is not None:
            body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
            headers["Content-Type"] = "application/json"
        request = urllib.request.Request(url, data=body, headers=headers, method=method)
        try:
            with urllib.request.urlopen(request, timeout=90) as response:
                raw = response.read()
        except urllib.error.HTTPError as error:
            response_body = error.read().decode("utf-8", errors="replace")
            raise OperationError(
                f"{method} {path} a échoué (HTTP {error.code}) : {response_body[:3000]}"
            ) from error
        except urllib.error.URLError as error:
            raise OperationError(f"{method} {path} inaccessible : {error}") from error

        decoded = json.loads(raw or b"{}")
        if "data" not in decoded:
            raise OperationError(f"Réponse API sans champ data pour {method} {path}.")
        return decoded["data"]


def run_preflight() -> None:
    result = subprocess.run(
        ["php", "artisan", "mibeko:prod-preflight"],
        cwd=REPO_ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    output = result.stdout + result.stderr
    print(output, end="" if output.endswith("\n") else "\n")
    proof = (
        "Lecture seule prouvée (SQLSTATE 25006" in output
        or "Lecture seule prouvée (SQLSTATE 42501" in output
    )
    if result.returncode != 0 or not proof:
        raise OperationError("Préflight interrompu : la lecture seule n'est pas prouvée.")


def dotenv_values(path: Path, names: set[str]) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        if key not in names:
            continue
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
            value = value[1:-1]
        values[key] = value
    return values


def database_values(prefix: str, *, env_only: bool) -> dict[str, str]:
    names = {
        f"{prefix}_DB_HOST",
        f"{prefix}_DB_PORT",
        f"{prefix}_DB_DATABASE",
        f"{prefix}_DB_USERNAME",
        f"{prefix}_DB_PASSWORD",
    }
    saved = {} if env_only else dotenv_values(REPO_ROOT / ".env", names)
    values = {name: os.getenv(name, saved.get(name, "")).strip() for name in names}
    missing = sorted(name for name, value in values.items() if not value)
    if missing:
        source = "le shell" if env_only else "le shell ou .env"
        raise OperationError(f"Variables {prefix} absentes de {source} : {', '.join(missing)}")
    return {
        "host": values[f"{prefix}_DB_HOST"],
        "port": values[f"{prefix}_DB_PORT"],
        "database": values[f"{prefix}_DB_DATABASE"],
        "username": values[f"{prefix}_DB_USERNAME"],
        "password": values[f"{prefix}_DB_PASSWORD"],
    }


def ro_database_values() -> dict[str, str]:
    return database_values("PROD_RO", env_only=False)


def rw_database_values() -> dict[str, str]:
    values = database_values("PROD_RW", env_only=True)
    readonly = ro_database_values()
    if any(values[key] != readonly[key] for key in ("host", "port", "database")):
        raise OperationError("Les profils PROD_RW et PROD_RO ne visent pas la même cible.")
    if values["port"] != "5434":
        raise OperationError("PROD_RW_DB_PORT doit être le port de tunnel 5434.")
    return values


def psql_query(
    connection: dict[str, str],
    sql: str,
    variables: dict[str, str] | None = None,
) -> str:
    environment = dict(os.environ)
    environment["PGPASSWORD"] = connection["password"]
    command = [
        "psql",
        "--no-psqlrc",
        "--quiet",
        "--tuples-only",
        "--no-align",
        "--set=ON_ERROR_STOP=1",
        "--host", connection["host"],
        "--port", connection["port"],
        "--username", connection["username"],
        "--dbname", connection["database"],
    ]
    for key, value in (variables or {}).items():
        command.append(f"--set={key}={value}")
    result = subprocess.run(
        command,
        input=sql,
        env=environment,
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode != 0:
        raise OperationError(f"psql a échoué : {result.stderr.strip()}")
    return result.stdout.strip()


def current_state(document_id: str) -> dict[str, Any]:
    uuid.UUID(document_id)
    sql = """
SELECT json_build_object(
  'id', d.id,
  'title', d.titre_officiel,
  'status', d.curation_status,
  'metadata', COALESCE(d.metadata, '{}'::jsonb),
  'live_nodes', (
    SELECT count(*) FROM structure_nodes sn
    WHERE sn.document_id=d.id AND sn.deleted_at IS NULL
  ),
  'live_articles', (
    SELECT count(*) FROM articles a
    WHERE a.document_id=d.id AND a.deleted_at IS NULL
  ),
  'missing_locators', (
    SELECT count(*)
    FROM articles a
    JOIN article_versions av ON av.article_id=a.id AND upper_inf(av.validity_period)
    WHERE a.document_id=d.id AND a.deleted_at IS NULL
      AND (av.source_locator IS NULL OR av.source_locator='{}'::jsonb)
  )
)
FROM legal_documents d
WHERE d.id=:'document_id'::uuid AND d.deleted_at IS NULL;
"""
    raw = psql_query(ro_database_values(), sql, {"document_id": document_id})
    for line in reversed(raw.splitlines()):
        try:
            state = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(state, dict):
            return state
    raise OperationError(f"Document {document_id} absent ou état illisible.")


def make_dump(prefix: str) -> Path:
    values = ro_database_values()
    dump_dir = SCRIPT_PATH.parent / "dumps"
    dump_dir.mkdir(parents=True, exist_ok=True)
    timestamp = dt.datetime.now(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    dump_path = dump_dir / f"prod-{prefix}-{timestamp}.dump"
    environment = dict(os.environ)
    environment["PGPASSWORD"] = values["password"]
    result = subprocess.run(
        [
            "pg_dump",
            "--format=custom",
            "--no-owner",
            "--no-acl",
            "--file", str(dump_path),
            "--host", values["host"],
            "--port", values["port"],
            "--username", values["username"],
            "--dbname", values["database"],
        ],
        env=environment,
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode != 0 or not dump_path.exists() or dump_path.stat().st_size == 0:
        raise OperationError(f"Dump impossible : {result.stderr.strip()}")
    print(f"Dump frais : {dump_path} ({dump_path.stat().st_size} octets)")
    return dump_path


def require_token() -> str:
    token = os.getenv("MIBEKO_API_TOKEN", "").strip()
    if not token:
        raise OperationError("MIBEKO_API_TOKEN doit être exporté dans le shell.")
    return token


def confirm(summary: str) -> None:
    print("\nOPÉRATION DE PRODUCTION")
    print(summary)
    if input("Saisir exactement o pour exécuter : ").strip() != "o":
        raise OperationError("Opération annulée par l'humain.")


def snapshot_path() -> Path:
    folder = SCRIPT_PATH.parent / "rollback"
    folder.mkdir(parents=True, exist_ok=True)
    timestamp = dt.datetime.now(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    return folder / f"arrete-3277-extraction-{timestamp}.json"


def snapshot_api(api: Api, document_id: str) -> dict[str, Any]:
    return api.data("GET", f"/admin/legal-documents/{document_id}/extraction-snapshot")


def repair_payload(
    target: dict[str, Any],
    fingerprint: str,
    *,
    execute: bool,
    motif: str,
) -> dict[str, Any]:
    return {
        "execute": execute,
        "expected_fingerprint": fingerprint,
        "motif": motif,
        "target": target,
    }


def operation_retirer(plan: dict[str, Any], execute: bool) -> None:
    document_id = plan["document_id"]
    state = current_state(document_id)
    if state["title"] != plan["document_title"]:
        raise OperationError("Le titre de garde ne correspond plus ; arrêter et remesurer.")
    if state["status"] not in {"published", "review"}:
        raise OperationError(f"Statut inattendu avant retrait : {state['status']}")
    print(json.dumps(state | {"metadata": "[préservée]"}, ensure_ascii=False, indent=2))
    if state["status"] == "review":
        print("Déjà retiré : aucune écriture nécessaire.")
        return
    print("SIMULATION : published → review ; 1 document ; articles inchangés ; catalogue -1.")
    if not execute:
        return

    api = Api(require_token())
    make_dump("arrete-3277-retrait")
    confirm(
        "Cible : Arrêté n° 3277 (1 document).\n"
        "Effet : published → review, retrait immédiat du catalogue ; 0 article modifié.\n"
        "Retour arrière : republication ultérieure par l'API, sous autorisation distincte."
    )
    result = api.data(
        "PATCH",
        f"/legal-documents/{document_id}",
        {
            "curation_status": "review",
            "motif": (
                "Retrait public provisoire : extraction incomplète de l'arrêté n° 3277 "
                "et de son cahier des charges (mibeko-dashboard#56)."
            ),
        },
    )
    if result.get("curation_status") != "review":
        raise OperationError(f"L'API n'a pas confirmé le statut review : {result}")
    after = current_state(document_id)
    if after["status"] != "review" or after["live_articles"] != state["live_articles"]:
        raise OperationError(f"Vérification après retrait incohérente : {after}")
    print("VÉRIFIÉ : document en review, articles inchangés, aucune publication implicite.")


def operation_reconstruire(plan: dict[str, Any], execute: bool) -> None:
    document_id = plan["document_id"]
    api = Api(require_token())
    state = current_state(document_id)
    if state["status"] not in {"published", "review"}:
        raise OperationError(f"Statut incompatible avec la Classe 2 : {state['status']}")
    snapshot = snapshot_api(api, document_id)
    motif = (
        "Reconstruction atomique contre le PDF officiel SGG, pages PDF 34 à 46, "
        "après contrôle visuel (mibeko-dashboard#56)."
    )
    dry_run = api.data(
        "POST",
        f"/admin/legal-documents/{document_id}/replace-extraction",
        repair_payload(
            plan["target"],
            snapshot["expected_fingerprint"],
            execute=False,
            motif=motif,
        ),
    )
    print(json.dumps(dry_run, ensure_ascii=False, indent=2))
    expected = plan["expected_after_repair"]
    if dry_run["plan"]["target_articles"] != expected["live_articles"]:
        raise OperationError("Le dry-run API n'annonce pas les 72 unités attendues.")
    if not execute or dry_run.get("already_applied"):
        return
    if state["status"] != "review":
        raise OperationError("Exécution refusée : retirer d'abord le document en review.")

    rollback = snapshot_path()
    rollback.write_text(json.dumps(snapshot, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    make_dump("arrete-3277-reconstruction")
    confirm(
        "Cible : extraction de l'Arrêté n° 3277, déjà en review.\n"
        f"Effet : {state['live_articles']} → {expected['live_articles']} unités vivantes, "
        f"{state['live_nodes']} → {expected['live_nodes']} divisions ; 0 DELETE physique.\n"
        f"Retour arrière : snapshot {rollback} via --operation rollback-extraction."
    )
    result = api.data(
        "POST",
        f"/admin/legal-documents/{document_id}/replace-extraction",
        repair_payload(
            plan["target"],
            snapshot["expected_fingerprint"],
            execute=True,
            motif=motif,
        ),
    )
    if not result.get("executed"):
        raise OperationError(f"La reconstruction n'a pas été exécutée : {result}")
    after = current_state(document_id)
    checks = {
        "status": after["status"] == "review",
        "live_nodes": after["live_nodes"] == expected["live_nodes"],
        "live_articles": after["live_articles"] == expected["live_articles"],
        "missing_locators": after["missing_locators"] == 0,
    }
    if not all(checks.values()):
        raise OperationError(f"Vérification après reconstruction échouée : {after} / {checks}")
    after_snapshot = snapshot_api(api, document_id)
    if after_snapshot["semantic_fingerprint"] != result["semantic_fingerprint"]:
        raise OperationError("L'empreinte sémantique relue diffère de l'empreinte appliquée.")
    print(json.dumps(result, ensure_ascii=False, indent=2))
    print(f"VÉRIFIÉ : cible complète en review. Snapshot de retour : {rollback}")


def operation_corriger_autorite(plan: dict[str, Any], execute: bool) -> None:
    document_id = plan["document_id"]
    patch = plan["metadata_patch"]
    state = current_state(document_id)
    metadata = state["metadata"]
    if not isinstance(metadata, dict):
        raise OperationError("Metadata courante illisible.")
    current_authority = metadata.get("autorite")
    if current_authority == patch["authority"] and metadata.get(patch["marker"]) is True:
        print("Autorité déjà corrigée : aucune écriture nécessaire.")
        return
    if current_authority != patch["expected_authority"]:
        raise OperationError(
            f"Autorité inattendue : {current_authority!r} ; arrêter et remesurer."
        )
    target_metadata = metadata | {patch["marker"]: True, "autorite": patch["authority"]}
    print(
        "SIMULATION : 1 ligne legal_documents.metadata ; "
        f"autorite {current_authority!r} → {patch['authority']!r}."
    )
    if not execute:
        return
    if state["status"] != "review":
        raise OperationError("Correction metadata refusée : le document doit rester en review.")

    make_dump("arrete-3277-autorite")
    rollback = snapshot_path()
    rollback = rollback.with_name(rollback.name.replace("extraction", "metadata"))
    rollback.write_text(json.dumps(metadata, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    confirm(
        "Cible : metadata de l'Arrêté n° 3277 (1 ligne).\n"
        "Effet : corriger uniquement l'autorité, sans changer le statut ni le contenu.\n"
        f"Retour arrière : metadata antérieure conservée dans {rollback}."
    )
    target_json = json.dumps(target_metadata, ensure_ascii=False, separators=(",", ":"))
    sql = """
BEGIN;
WITH updated AS (
  UPDATE legal_documents
  SET metadata=:'target_metadata'::jsonb
  WHERE id=:'document_id'::uuid
    AND deleted_at IS NULL
    AND curation_status='review'
    AND titre_officiel=:'expected_title'
    AND metadata->>'autorite'=:'expected_authority'
  RETURNING id
)
SELECT count(*) FROM updated;
COMMIT;
"""
    raw = psql_query(
        rw_database_values(),
        sql,
        {
            "document_id": document_id,
            "target_metadata": target_json,
            "expected_title": plan["document_title"],
            "expected_authority": patch["expected_authority"],
        },
    )
    counts = [line.strip() for line in raw.splitlines() if line.strip().isdigit()]
    if counts != ["1"]:
        raise OperationError(f"La correction devait toucher exactement 1 ligne : {raw!r}")
    after = current_state(document_id)
    if after["metadata"] != target_metadata or after["status"] != "review":
        raise OperationError("La metadata relue ne correspond pas exactement à la cible.")
    print("VÉRIFIÉ : autorité corrigée sur 1 ligne, document toujours en review.")


def operation_rollback_extraction(
    plan: dict[str, Any],
    execute: bool,
    rollback_file: Path | None,
) -> None:
    if rollback_file is None:
        raise OperationError("--snapshot est obligatoire pour rollback-extraction.")
    snapshot = json.loads(rollback_file.read_text(encoding="utf-8"))
    document_id = plan["document_id"]
    if snapshot.get("target", {}).get("document_id") != document_id:
        raise OperationError("Le snapshot ne correspond pas au document 3277.")
    api = Api(require_token())
    current = snapshot_api(api, document_id)
    motif = "Retour arrière de la reconstruction #56 depuis le snapshot local contrôlé."
    dry_run = api.data(
        "POST",
        f"/admin/legal-documents/{document_id}/replace-extraction",
        repair_payload(
            snapshot["target"],
            current["expected_fingerprint"],
            execute=False,
            motif=motif,
        ),
    )
    print(json.dumps(dry_run, ensure_ascii=False, indent=2))
    if not execute or dry_run.get("already_applied"):
        return
    if current_state(document_id)["status"] != "review":
        raise OperationError("Rollback refusé : le document doit rester en review.")
    make_dump("arrete-3277-rollback-extraction")
    confirm(
        "Cible : extraction de l'Arrêté n° 3277 en review.\n"
        f"Effet : restaurer exactement le snapshot {rollback_file}.\n"
        "Retour arrière : dump frais pris juste avant cette restauration."
    )
    result = api.data(
        "POST",
        f"/admin/legal-documents/{document_id}/replace-extraction",
        repair_payload(
            snapshot["target"],
            current["expected_fingerprint"],
            execute=True,
            motif=motif,
        ),
    )
    after = snapshot_api(api, document_id)
    if after["semantic_fingerprint"] != snapshot["semantic_fingerprint"]:
        raise OperationError("Le rollback relu ne correspond pas au snapshot attendu.")
    print(json.dumps(result, ensure_ascii=False, indent=2))
    print("VÉRIFIÉ : extraction antérieure restaurée, document toujours en review.")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--operation", choices=OPERATIONS, required=True)
    parser.add_argument("--execute", action="store_true", help="Autorise l'écriture après confirmation.")
    parser.add_argument("--snapshot", type=Path, help="Snapshot local pour rollback-extraction.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    plan = load_plan()
    run_preflight()
    if args.operation == "retirer":
        operation_retirer(plan, args.execute)
    elif args.operation == "reconstruire":
        operation_reconstruire(plan, args.execute)
    elif args.operation == "corriger-autorite":
        operation_corriger_autorite(plan, args.execute)
    elif args.operation == "rollback-extraction":
        operation_rollback_extraction(plan, args.execute, args.snapshot)
    else:
        raise AssertionError(args.operation)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except OperationError as error:
        print(f"ERREUR : {error}", file=sys.stderr)
        raise SystemExit(1) from error
