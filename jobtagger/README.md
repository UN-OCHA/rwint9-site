# Job tagger testing

Test executed using `text-embedding-3-small`.

## Run it

```bash
drush cr
drush scr jobtagger/tagjob.php -- 4056746
drush scr jobtagger/tagjob.php -- 4059289
drush scr jobtagger/tagjob.php -- 4058804
drush scr jobtagger/tagjob.php -- 4058473
drush scr jobtagger/tagjob.php -- 4057645
```

## Results

| Id | Link | Selected category | AI category |
| - | - | - | - |
| 4056746 | https://reliefweb.int/job/4056746/arts-teacher | Program/Project Management | Monitoring and Evaluation |
| 4059289 | https://reliefweb.int/job/4059289/logistics-manager-nairobi | Logistics/Procurement | Donor Relations/Grants Management |
| 4058804 | https://reliefweb.int/job/4058804/physiotherapist | Program/Project Management |Human Resources |
| 4058473 | https://reliefweb.int/job/4058473/legal-officer-re-opening | Administration/Finance | Donor Relations/Grants Management |
| 4057645 | https://reliefweb.int/job/4057645/assistant-transport-optimization-officer-unops-lica8-budapest | Logistics/Procurement | Human Resources |
| 4059325 | https://reliefweb.int/job/4059325/people-and-culture-manager | Human Resource | **Human Resource** |
| 4059225 | https://reliefweb.int/job/4059225/compensation-benefits-analyst | Human Resource | **Human Resource** |
| 4059164 | https://reliefweb.int/job/4059164/recruitment-and-social-media-assistant | Human Resource | **Human Resource** |
| 4059360 | https://reliefweb.int/job/4059360/refugee-status-determination-volunteer-legal-advisor | Advocacy/Communications | Human Resources |
| 4059377 | https://reliefweb.int/job/4059377/protection-field-officer-1 | Advocacy/Communications | Human Resources |
| 4059774 | https://reliefweb.int/job/4059774/casework-supervisor-hsprs | Advocacy/Communications | |
| 4060112 | https://reliefweb.int/job/4060112/program-associate | Donor Relations/Grants Management | |
| 4065235 | https://reliefweb.int/job/4065235/regional-senior-auditor | Administration/Finance | |

https://reliefweb.int/job/4059374/chet-project-manager

## Raw results

Bold one is the one picked by an editor.

## Results for 4056746

| Category | Percentage |
| -------- | ---------- |
| Monitoring and Evaluation | 24.17 |
| Donor Relations/Grants Management | 23.44 |
| Advocacy/Communications | 22.45 |
| Human Resources | 20.75 |
| Information and Communications Technology | 16.9 |
| **Program/Project Management** | 16.69 |
| Administration/Finance | 16.57 |
| Logistics/Procurement | 15.61 |
| Information Management | 15.29 |

## Results for 4059289

| Category | Percentage |
| -------- | ---------- |
| Donor Relations/Grants Management | 44.96 |
| **Logistics/Procurement** | 35.47 |
| Human Resources | 34.18 |
| Advocacy/Communications | 32.62 |
| Monitoring and Evaluation | 30.92 |
| Program/Project Management | 30 |
| Administration/Finance | 29.76 |
| Information Management | 29.08 |
| Information and Communications Technology | 25.51 |

## Results for 4058804

| Category | Percentage |
| -------- | ---------- |
| Human Resources | 27.35 |
| Monitoring and Evaluation | 27.27 |
| Donor Relations/Grants Management | 25.15 |
| Information Management | 24.72 |
| Advocacy/Communications | 22.89 |
| Administration/Finance | 22.1 |
| **Program/Project Management** | 21.48 |
| Information and Communications Technology | 19.54 |
| Logistics/Procurement | 17.65 |

## Results for 4058473

| Category | Percentage |
| -------- | ---------- |
| Donor Relations/Grants Management | 27.98 |
| Advocacy/Communications | 27.72 |
| Human Resources | 23.25 |
| **Administration/Finance** | 21.2 |
| Information Management | 20.12 |
| Program/Project Management | 18.24 |
| Logistics/Procurement | 17.65 |
| Monitoring and Evaluation | 17.18 |
| Information and Communications Technology | 14.68 |

## Results for 4057645

| Category | Percentage |
| -------- | ---------- |
| Human Resources | 31.55 |
| Administration/Finance | 30.33 |
| Program/Project Management | 28.03 |
| Information Management | 27.89 |
| Donor Relations/Grants Management | 27.57 |
| Advocacy/Communications | 27.12 |
| **Logistics/Procurement** | 26.67 |
| Information and Communications Technology | 25.79 |
| Monitoring and Evaluation | 22.6 |

## Results for 4059325

| Category | Percentage |
| -------- | ---------- |
| **Human Resources** | 42.77 |
| Donor Relations/Grants Management | 29.75 |
| Monitoring and Evaluation | 25.98 |
| Advocacy/Communications | 25.75 |
| Administration/Finance | 24.05 |
| Program/Project Management | 22.86 |
| Information Management | 17.46 |
| Logistics/Procurement | 16.69 |
| Information and Communications Technology | 15.32 |

## Results for 4059225

| Category | Percentage |
| -------- | ---------- |
| **Human Resources** | 45.31 |
| Donor Relations/Grants Management | 24.64 |
| Advocacy/Communications | 23.15 |
| Logistics/Procurement | 19.49 |
| Program/Project Management | 17.15 |
| Administration/Finance | 16.01 |
| Monitoring and Evaluation | 15.91 |
| Information and Communications Technology | 15.33 |
| Information Management | 14.5 |

## Results for 4059164

| Category | Percentage |
| -------- | ---------- |
| **Human Resources** | 43.84 |
| Advocacy/Communications | 29.14 |
| Donor Relations/Grants Management | 28.34 |
| Administration/Finance | 25.33 |
| Program/Project Management | 23.96 |
| Information Management | 22.2 |
| Monitoring and Evaluation | 22.15 |
| Information and Communications Technology | 20.17 |
| Logistics/Procurement | 18.68 |

## Results for 4059360

| Category | Percentage |
| -------- | ---------- |
| Human Resources | 30.32 |
| **Advocacy/Communications** | 23.16 |
| Donor Relations/Grants Management | 21.84 |
| Monitoring and Evaluation | 20.27 |
| Program/Project Management | 17.65 |
| Information Management | 13.19 |
| Administration/Finance | 10.91 |
| Information and Communications Technology | 7.56 |
| Logistics/Procurement | 6.83 |

## Results for 4059377

| Category | Percentage |
| -------- | ---------- |
| Human Resources | 35.21 |
| Donor Relations/Grants Management | 33.89 |
| **Advocacy/Communications** | 30.42 |
| Information Management | 28.08 |
| Monitoring and Evaluation | 25.63 |
| Program/Project Management | 23.92 |
| Information and Communications Technology | 21.72 |
| Administration/Finance | 19.85 |
| Logistics/Procurement | 16.34 |

## Results for 4059374

| Category | Percentage |
| -------- | ---------- |
| Human Resources | 44.58 |
| Monitoring and Evaluation | 28.77 |
| Advocacy/Communications | 28.68 |
| Program/Project Management | 27.13 |
| Information Management | 24.89 |
| Donor Relations/Grants Management | 23.74 |
| Information and Communications Technology | 22.25 |
| Logistics/Procurement | 18.6 |
| Administration/Finance | 15.77 |

## Definitions

> Can you give a definition of the career category "Program/Project Management"

> Can you give an example of a job posting for a "Program/Project Management"
