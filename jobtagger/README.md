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
