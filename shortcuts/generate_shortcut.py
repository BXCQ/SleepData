#!/usr/bin/env python3
"""Generate SleepData AutoUpload .shortcut (binary plist)."""

from __future__ import annotations

import plistlib
import uuid
from pathlib import Path


def uid() -> str:
    return str(uuid.uuid4()).upper()


def attachment_action(output_uuid: str, output_name: str, aggrandizements=None) -> dict:
    value = {
        "OutputUUID": output_uuid,
        "OutputName": output_name,
        "Type": "ActionOutput",
    }
    if aggrandizements:
        value["Aggrandizements"] = aggrandizements
    return {
        "Value": value,
        "WFSerializationType": "WFTextTokenAttachment",
    }


def attachment_variable(name: str, aggrandizements=None) -> dict:
    value = {
        "Type": "Variable",
        "VariableName": name,
    }
    if aggrandizements:
        value["Aggrandizements"] = aggrandizements
    return {
        "Value": value,
        "WFSerializationType": "WFTextTokenAttachment",
    }


def text_token(string: str, attachments: dict | None = None) -> dict:
    value: dict = {"string": string}
    if attachments:
        value["attachmentsByRange"] = attachments
    return {
        "Value": value,
        "WFSerializationType": "WFTextTokenString",
    }


def dict_string_item(key: str, value_token: dict) -> dict:
    return {
        "WFItemType": 0,
        "WFKey": text_token(key),
        "WFValue": value_token,
    }


def build() -> dict:
    # Action UUIDs
    ask_url = uid()
    set_url = uid()
    ask_token = uid()
    set_token = uid()
    date_now = uid()
    format_date = uid()
    set_date = uid()
    find_sleep = uid()
    nothing_text = uid()
    set_samples = uid()
    repeat_group = uid()
    repeat_end = uid()
    prop_value = uid()
    prop_start = uid()
    format_start = uid()
    prop_end = uid()
    format_end = uid()
    line_text = uid()
    append_samples = uid()
    get_url = uid()
    show_result = uid()

    actions: list[dict] = []

    # 1) Ask API URL
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.ask",
            "WFWorkflowActionParameters": {
                "UUID": ask_url,
                "WFAskActionPrompt": "睡眠数据 API 地址（shortcut-api.php）",
                "WFInputType": "URL",
                "WFAskActionDefaultURL": "https://blog.ybyq.wang/usr/plugins/SleepData/shortcut-api.php",
            },
        }
    )
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.setvariable",
            "WFWorkflowActionParameters": {
                "UUID": set_url,
                "WFVariableName": "APIURL",
                "WFInput": attachment_action(ask_url, "Provided Input"),
            },
        }
    )

    # 2) Ask token
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.ask",
            "WFWorkflowActionParameters": {
                "UUID": ask_token,
                "WFAskActionPrompt": "访问令牌（与插件配置一致）",
                "WFInputType": "Text",
            },
        }
    )
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.setvariable",
            "WFWorkflowActionParameters": {
                "UUID": set_token,
                "WFVariableName": "Token",
                "WFInput": attachment_action(ask_token, "Provided Input"),
            },
        }
    )

    # 3) Today date string
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.currentdate",
            "WFWorkflowActionParameters": {"UUID": date_now},
        }
    )
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.format.date",
            "WFWorkflowActionParameters": {
                "UUID": format_date,
                "WFDateFormatStyle": "Custom",
                "WFDateFormat": "yyyy-MM-dd",
                "WFTimeFormatStyle": "None",
                "WFInput": attachment_action(date_now, "Current Date"),
            },
        }
    )
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.setvariable",
            "WFWorkflowActionParameters": {
                "UUID": set_date,
                "WFVariableName": "WakeDate",
                "WFInput": attachment_action(format_date, "Formatted Date"),
            },
        }
    )

    # 4) Find Sleep samples (Start Date is in the last 2 days)
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.filter.health.quantity",
            "WFWorkflowActionParameters": {
                "UUID": find_sleep,
                "WFContentItemFilter": {
                    "Value": {
                        "WFActionParameterFilterPrefix": 1,
                        "WFContentPredicateBoundedDate": False,
                        "WFActionParameterFilterTemplates": [
                            {
                                "Bounded": True,
                                "Operator": 4,
                                "Property": "Type",
                                "Removable": False,
                                "Values": {
                                    "Enumeration": {
                                        "Value": "Sleep",
                                        "WFSerializationType": "WFStringSubstitutableState",
                                    }
                                },
                            },
                            {
                                "Bounded": True,
                                "Operator": 1001,
                                "Property": "Start Date",
                                "Removable": False,
                                "Values": {
                                    "Number": "2",
                                    "Unit": 16,
                                },
                            },
                        ],
                    },
                    "WFSerializationType": "WFContentPredicateTableTemplate",
                },
                "WFContentItemSortProperty": "Start Date",
                "WFContentItemSortOrder": "Oldest First",
            },
        }
    )

    # 5) Empty samples_text variable
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.gettext",
            "WFWorkflowActionParameters": {
                "UUID": nothing_text,
                "WFTextActionText": "",
            },
        }
    )
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.setvariable",
            "WFWorkflowActionParameters": {
                "UUID": set_samples,
                "WFVariableName": "SamplesText",
                "WFInput": attachment_action(nothing_text, "Text"),
            },
        }
    )

    # 6) Repeat each health sample
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.repeat.each",
            "WFWorkflowActionParameters": {
                "GroupingIdentifier": repeat_group,
                "WFControlFlowMode": 0,
                "WFInput": attachment_action(find_sleep, "Health Samples"),
            },
        }
    )

    # Value / Start / End from Repeat Item
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.properties.health.quantity",
            "WFWorkflowActionParameters": {
                "UUID": prop_value,
                "WFContentItemPropertyName": "Value",
                "WFInput": attachment_variable("Repeat Item"),
            },
        }
    )
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.properties.health.quantity",
            "WFWorkflowActionParameters": {
                "UUID": prop_start,
                "WFContentItemPropertyName": "Start Date",
                "WFInput": attachment_variable("Repeat Item"),
            },
        }
    )
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.format.date",
            "WFWorkflowActionParameters": {
                "UUID": format_start,
                "WFDateFormatStyle": "ISO 8601",
                "WFISO8601IncludeTime": True,
                "WFTimeFormatStyle": "Medium",
                "WFInput": attachment_action(prop_start, "Start Date"),
            },
        }
    )
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.properties.health.quantity",
            "WFWorkflowActionParameters": {
                "UUID": prop_end,
                "WFContentItemPropertyName": "End Date",
                "WFInput": attachment_variable("Repeat Item"),
            },
        }
    )
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.format.date",
            "WFWorkflowActionParameters": {
                "UUID": format_end,
                "WFDateFormatStyle": "ISO 8601",
                "WFISO8601IncludeTime": True,
                "WFTimeFormatStyle": "Medium",
                "WFInput": attachment_action(prop_end, "End Date"),
            },
        }
    )

    # line: value|start|end\n
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.gettext",
            "WFWorkflowActionParameters": {
                "UUID": line_text,
                "WFTextActionText": text_token(
                    "￼|￼|￼\n",
                    {
                        "{0, 1}": {
                            "OutputUUID": prop_value,
                            "OutputName": "Value",
                            "Type": "ActionOutput",
                        },
                        "{2, 1}": {
                            "OutputUUID": format_start,
                            "OutputName": "Formatted Date",
                            "Type": "ActionOutput",
                        },
                        "{4, 1}": {
                            "OutputUUID": format_end,
                            "OutputName": "Formatted Date",
                            "Type": "ActionOutput",
                        },
                    },
                ),
            },
        }
    )
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.appendvariable",
            "WFWorkflowActionParameters": {
                "UUID": append_samples,
                "WFVariableName": "SamplesText",
                "WFInput": attachment_action(line_text, "Text"),
            },
        }
    )

    # End repeat
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.repeat.each",
            "WFWorkflowActionParameters": {
                "UUID": repeat_end,
                "GroupingIdentifier": repeat_group,
                "WFControlFlowMode": 2,
            },
        }
    )

    # 7) POST JSON
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.downloadurl",
            "WFWorkflowActionParameters": {
                "UUID": get_url,
                "ShowHeaders": False,
                "WFHTTPMethod": "POST",
                "WFHTTPBodyType": "JSON",
                "WFURL": attachment_variable("APIURL"),
                "WFJSONValues": {
                    "Value": {
                        "WFDictionaryFieldValueItems": [
                            dict_string_item(
                                "access_token",
                                text_token(
                                    "￼",
                                    {
                                        "{0, 1}": {
                                            "Type": "Variable",
                                            "VariableName": "Token",
                                        }
                                    },
                                ),
                            ),
                            dict_string_item(
                                "date",
                                text_token(
                                    "￼",
                                    {
                                        "{0, 1}": {
                                            "Type": "Variable",
                                            "VariableName": "WakeDate",
                                        }
                                    },
                                ),
                            ),
                            dict_string_item(
                                "samples_text",
                                text_token(
                                    "￼",
                                    {
                                        "{0, 1}": {
                                            "Type": "Variable",
                                            "VariableName": "SamplesText",
                                        }
                                    },
                                ),
                            ),
                        ]
                    },
                    "WFSerializationType": "WFDictionaryFieldValue",
                },
            },
        }
    )

    # 8) Show result
    actions.append(
        {
            "WFWorkflowActionIdentifier": "is.workflow.actions.showresult",
            "WFWorkflowActionParameters": {
                "UUID": show_result,
                "Text": attachment_action(get_url, "Contents of URL"),
            },
        }
    )

    return {
        "WFWorkflowClientVersion": "2700.0.4",
        "WFWorkflowClientRelease": "26A0000a",
        "WFWorkflowMinimumClientVersion": 900,
        "WFWorkflowMinimumClientVersionString": "900",
        "WFWorkflowName": "睡眠数据自动上传",
        "WFWorkflowIcon": {
            "WFWorkflowIconGlyphNumber": 59830,
            "WFWorkflowIconStartColor": 431817727,
        },
        "WFWorkflowTypes": ["WatchKit", "NCWidget"],
        "WFWorkflowInputContentItemClasses": [
            "WFAppStoreAppContentItem",
            "WFArticleContentItem",
            "WFContactContentItem",
            "WFDateContentItem",
            "WFEmailAddressContentItem",
            "WFGenericFileContentItem",
            "WFImageContentItem",
            "WFiTunesProductContentItem",
            "WFLocationContentItem",
            "WFDCContentItem",
            "WFPDFContentItem",
            "WFPhoneNumberContentItem",
            "WFRichTextContentItem",
            "WFSafariWebPageContentItem",
            "WFStringContentItem",
            "WFURLContentItem",
        ],
        "WFWorkflowOutputContentItemClasses": [],
        "WFWorkflowHasOutputFallback": False,
        "WFWorkflowImportQuestions": [
            {
                "ActionIndex": 0,
                "Category": "Parameter",
                "ParameterKey": "WFAskActionDefaultURL",
                "Text": "填写你的 shortcut-api.php 完整地址",
                "DefaultValue": "https://blog.ybyq.wang/usr/plugins/SleepData/shortcut-api.php",
            }
        ],
        "WFWorkflowActions": actions,
    }


def main() -> None:
    out_dir = Path(__file__).resolve().parent
    data = build()
    out_bin = out_dir / "SleepData-AutoUpload.shortcut"
    out_xml = out_dir / "SleepData-AutoUpload.plist.xml"
    with out_bin.open("wb") as f:
        plistlib.dump(data, f, fmt=plistlib.FMT_BINARY)
    with out_xml.open("wb") as f:
        plistlib.dump(data, f, fmt=plistlib.FMT_XML)
    print(f"Wrote {out_bin} ({out_bin.stat().st_size} bytes)")
    print(f"Wrote {out_xml} ({out_xml.stat().st_size} bytes)")


if __name__ == "__main__":
    main()
